<?php

declare(strict_types=1);

namespace Heimseiten\ContaoBackupBundle;

use Composer\InstalledVersions;
use Contao\CoreBundle\Doctrine\Backup\Backup;
use Contao\CoreBundle\Doctrine\Backup\BackupManager;
use Contao\CoreBundle\Monolog\ContaoContext;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipStream\CompressionMethod;
use ZipStream\OperationMode;
use ZipStream\ZipStream;

/**
 * Builds the download responses for the "Sicherung" backend module.
 *
 * The file/full archives are streamed straight to the browser with ZipStream: no temporary
 * ZIP is ever written to disk and no whole file is held in memory, so even multi-GB sites
 * download with a small memory_limit. Because bytes flow continuously from the first moment,
 * the web server's read timeout (the usual wall for "build first, then send") is never hit.
 */
final class BackupDownloader
{
    /**
     * Files and folders that make up a full file backup. Some may not exist in
     * every installation - missing paths are skipped silently.
     */
    public const PATHS = [
        'composer.json',
        'composer.lock',
        'config',
        'contao',
        'src',
        'templates',
        'translations',
        'migrations',
        'system/config/localconfig.php',
        'files',
    ];

    /**
     * Version metadata written into every archive, so a restore can check the
     * compatibility between the backup and the target installation up front.
     */
    public const MANIFEST_NAME = 'backup-manifest.json';

    /**
     * Where an archive is stored when it is kept on the server instead of downloaded.
     * Same directory Contao writes its database backups to: outside the web root (never
     * reachable over HTTP) and one of the places the restore offers for selection.
     */
    public const STORE_DIR = 'var/backups';

    /**
     * Free disk space required on top of the archive itself, so writing it cannot fill
     * the disk to the last byte.
     */
    private const DISK_SPACE_MARGIN = 64 * 1024 * 1024;

    /**
     * Shortest interval between two progress reports while storing (seconds).
     */
    private const PROGRESS_INTERVAL = 0.4;

    /**
     * Memory ZipStream's simulate pass needs per file, measured on a 34k-file site
     * (142 MiB total): 4 KiB plus a margin. Used to decide whether simulating is safe at all.
     */
    private const SIMULATE_BYTES_PER_FILE = 4608;

    /**
     * Share of the still available memory the simulation may claim. The rest is left to the
     * framework and the response, which keep working while the archive streams.
     */
    private const SIMULATE_MEMORY_SHARE = 0.6;

    public function __construct(
        private readonly BackupManager $backupManager,
        private readonly Connection $connection,
        private readonly string $projectDir,
        private readonly LoggerInterface $logger,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Database only: Contao's own backup (gzip SQL, also stored in var/backups),
     * streamed as a download.
     */
    public function createDatabaseResponse(): Response
    {
        $this->liftTimeLimit();

        $backup = $this->createDatabaseBackup();
        $stream = $this->backupManager->readStream($backup);

        $response = new StreamedResponse(function () use ($stream): void {
            $this->flushOutputBuffers();

            if (\is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        });

        $response->headers->set('Content-Type', 'application/gzip');
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $this->filename('database-backup', '.sql.gz')),
        );

        // Known size up front, so a progress bar can show exact percentages here too. Only set
        // it when the size is actually known: a wrong Content-Length would truncate the download.
        if ($backup->getSize() > 0) {
            $response->headers->set('Content-Length', (string) $backup->getSize());
            $response->headers->set('X-Backup-Size', (string) $backup->getSize());
        }

        return $response;
    }

    /**
     * Files only: the relevant files/folders streamed into a ZIP on the fly.
     */
    public function createFilesResponse(): Response
    {
        $this->liftTimeLimit();

        $manifest = $this->buildManifest();
        $size = $this->computeZipSize(null, $manifest);

        $response = new StreamedResponse(function () use ($manifest): void {
            $this->flushOutputBuffers();

            $zip = $this->openZipStream();
            $zip->addFile(self::MANIFEST_NAME, $manifest);
            $this->addProjectFiles($zip);
            $zip->finish();
        });

        return $this->prepareZipResponse($response, $this->filename('files-backup', '.zip'), $size);
    }

    /**
     * Everything in one streamed ZIP: the database backup (under database/) plus the files.
     */
    public function createFullResponse(): Response
    {
        $this->liftTimeLimit();

        // Create the database backup BEFORE streaming starts: should it fail, the user still
        // gets a clean error page (nothing has been sent yet).
        $backup = $this->createDatabaseBackup();
        $manifest = $this->buildManifest();
        $size = $this->computeZipSize($backup, $manifest);

        $response = new StreamedResponse(function () use ($backup, $manifest): void {
            $this->flushOutputBuffers();

            $zip = $this->openZipStream();
            $zip->addFile(self::MANIFEST_NAME, $manifest);

            $stream = $this->backupManager->readStream($backup);

            if (\is_resource($stream)) {
                // Pass the same exactSize the up-front simulation used, so a drift between
                // the predicted and the actually streamed dump size fails loudly (ZipStream
                // throws) instead of silently producing a ZIP that mismatches the announced
                // Content-Length and gets truncated by the browser. Only when the size is
                // known (>0) - matching computeZipSize(), which skips the length otherwise.
                $dbSize = $backup->getSize();

                if ($dbSize > 0) {
                    $zip->addFileFromStream('database/'.$backup->getFilename(), $stream, exactSize: $dbSize);
                } else {
                    $zip->addFileFromStream('database/'.$backup->getFilename(), $stream);
                }

                fclose($stream);
            }

            $this->addProjectFiles($zip);
            $zip->finish();
        });

        return $this->prepareZipResponse($response, $this->filename('full-backup', '.zip'), $size);
    }

    /**
     * Writes a backup into var/backups instead of sending it to the browser - the counterpart
     * to the download for everyone who would upload the archive back to the server anyway
     * (it can be fetched per FTP later, and the restore offers it for selection right away).
     *
     * The archive is built with the same streaming pass as a download, but into a file: no
     * temporary copy, constant memory. It is written to "<name>.partial" and only renamed
     * into place once it is complete, so a half-written archive can never be picked up as a
     * restore source, and a failure (disk full, unreadable file) leaves nothing behind.
     *
     * @param bool                             $withDatabase pack the database dump as well
     * @param callable(int, int|null):void|null $onProgress   receives (bytes written, total or null),
     *                                                        throttled to PROGRESS_INTERVAL
     * @param string|null                       $prefix       file name prefix, so automatic backups
     *                                                        stay distinguishable from manual ones
     *
     * @return array{name: string, path: string, size: int}
     *
     * @throws \RuntimeException if the directory is unusable or the disk is too full
     */
    public function storeArchive(bool $withDatabase, callable|null $onProgress = null, string|null $prefix = null): array
    {
        $this->liftTimeLimit();

        $directory = $this->storeDirectory();

        // Create the database backup BEFORE the archive is opened: should it fail, nothing
        // has been written yet.
        $backup = $withDatabase ? $this->createDatabaseBackup() : null;
        $manifest = $this->buildManifest();
        $size = $this->computeZipSize($backup, $manifest);

        if (null !== $size) {
            $this->assertEnoughDiskSpace($directory, $size);
        }

        $name = $this->filename($prefix ?? ($withDatabase ? 'full-backup' : 'files-backup'), '.zip');
        $target = $directory.'/'.$name;
        $partial = $target.'.partial';
        $handle = fopen($partial, 'wb');

        if (!\is_resource($handle)) {
            throw new \RuntimeException(\sprintf('Could not open "%s" for writing.', self::STORE_DIR.'/'.$name.'.partial'));
        }

        $lastReport = 0.0;
        $report = static function (int $written) use ($onProgress, $size, &$lastReport): void {
            if (null === $onProgress) {
                return;
            }

            $now = microtime(true);

            if ($now - $lastReport < self::PROGRESS_INTERVAL) {
                return;
            }

            $lastReport = $now;
            $onProgress($written, $size);
        };

        try {
            // No output flushing here: the bytes go into the file, while the caller keeps the
            // (possibly very long) request alive with its own progress output.
            $zip = new ZipStream(
                outputStream: $handle,
                sendHttpHeaders: false,
                defaultCompressionMethod: CompressionMethod::STORE,
            );

            $zip->addFile(self::MANIFEST_NAME, $manifest);

            if (null !== $backup) {
                $stream = $this->backupManager->readStream($backup);

                if (\is_resource($stream)) {
                    $dbSize = $backup->getSize();

                    if ($dbSize > 0) {
                        $zip->addFileFromStream('database/'.$backup->getFilename(), $stream, exactSize: $dbSize);
                    } else {
                        $zip->addFileFromStream('database/'.$backup->getFilename(), $stream);
                    }

                    fclose($stream);
                }

                $report((int) ftell($handle));
            }

            $this->addProjectFiles($zip, static function () use ($handle, $report): void {
                $report((int) ftell($handle));
            });

            $zip->finish();
            fclose($handle);

            if (!@rename($partial, $target)) {
                throw new \RuntimeException(\sprintf('Could not move the finished archive to "%s".', self::STORE_DIR.'/'.$name));
            }
        } catch (\Throwable $e) {
            if (\is_resource($handle)) {
                fclose($handle);
            }

            @unlink($partial);

            $this->logger->error(
                'Backup: storing the archive on the server failed - '.$e->getMessage(),
                ['contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR), 'exception' => $e],
            );

            throw $e;
        }

        $written = (int) filesize($target);

        $this->logger->info(
            \sprintf('Backup stored on the server: %s (%d bytes)', self::STORE_DIR.'/'.$name, $written),
            ['contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL)],
        );

        return ['name' => $name, 'path' => self::STORE_DIR.'/'.$name, 'size' => $written];
    }

    /**
     * Creates a database backup in var/backups (Contao's own backup, subject to the
     * retention settings) without sending anything to the browser.
     *
     * @return array{name: string, path: string, size: int}
     */
    public function storeDatabaseBackup(): array
    {
        $this->liftTimeLimit();
        $this->storeDirectory();

        $backup = $this->createDatabaseBackup();

        $this->logger->info(
            \sprintf('Database backup stored on the server: %s', self::STORE_DIR.'/'.$backup->getFilename()),
            ['contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL)],
        );

        return [
            'name' => $backup->getFilename(),
            'path' => self::STORE_DIR.'/'.$backup->getFilename(),
            'size' => $backup->getSize(),
        ];
    }

    /**
     * The archives already stored on the server, newest first - so the module can show what
     * is there (and how much space it uses) without duplicating the directory logic.
     *
     * @return list<array{name: string, path: string, size: int, modified: int}>
     */
    public function storedArchives(): array
    {
        $directory = $this->projectDir.'/'.self::STORE_DIR;
        $archives = [];

        foreach (glob($directory.'/*.[zZ][iI][pP]') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }

            $archives[] = [
                'name' => basename($file),
                'path' => self::STORE_DIR.'/'.basename($file),
                'size' => (int) filesize($file),
                'modified' => (int) filemtime($file),
            ];
        }

        usort($archives, static fn (array $a, array $b): int => $b['modified'] <=> $a['modified']);

        return $archives;
    }

    /**
     * @throws \RuntimeException if the directory cannot be created or written to
     */
    private function storeDirectory(): string
    {
        $directory = $this->projectDir.'/'.self::STORE_DIR;

        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('The directory "%s" does not exist and could not be created.', self::STORE_DIR));
        }

        if (!is_writable($directory)) {
            throw new \RuntimeException(\sprintf('The directory "%s" is not writable for the web server user.', self::STORE_DIR));
        }

        return $directory;
    }

    /**
     * @throws \RuntimeException if the archive would not fit
     */
    private function assertEnoughDiskSpace(string $directory, int $requiredBytes): void
    {
        $free = @disk_free_space($directory);

        if (false === $free) {
            return; // unknown - proceed
        }

        if ($free < $requiredBytes + self::DISK_SPACE_MARGIN) {
            throw new \RuntimeException(\sprintf(
                'Not enough free disk space: the archive needs about %d MB but only %d MB are free.',
                (int) round($requiredBytes / 1048576),
                (int) round($free / 1048576),
            ));
        }
    }

    private function createDatabaseBackup(): Backup
    {
        $config = $this->backupManager->createCreateConfig();
        $this->backupManager->create($config);

        return $config->getBackup();
    }

    /**
     * Builds the backup-manifest.json content: the versions this backup was created
     * with plus the full installed package list. A restore uses it to check the
     * compatibility with the target installation before anything is touched.
     * Built once per download and reused for the size simulation, so the announced
     * Content-Length always matches the streamed bytes.
     */
    private function buildManifest(): string
    {
        $packages = [];

        foreach (InstalledVersions::getInstalledPackages() as $name) {
            $packages[$name] = InstalledVersions::getPrettyVersion($name);
        }

        ksort($packages);

        $databaseVersion = '';

        try {
            $databaseVersion = (string) $this->connection->fetchOne('SELECT VERSION()');
        } catch (\Throwable) {
            // Purely informational - a backup without it is still fine.
        }

        return json_encode(
            [
                'format' => 1,
                'createdAt' => date(\DATE_ATOM),
                'contaoVersion' => $this->packageVersion('contao/core-bundle'),
                'phpVersion' => \PHP_VERSION,
                'databaseVersion' => $databaseVersion,
                'bundleVersion' => $this->packageVersion('heimseiten/contao-backup-bundle'),
                'packages' => $packages,
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ) ?: '{}';
    }

    private function packageVersion(string $name): string|null
    {
        try {
            return InstalledVersions::getPrettyVersion($name);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A ZipStream that writes straight to the output buffer and stores files without
     * re-compression: the media in files/ is already compressed, so deflating it again only
     * burns time for virtually no size gain. The HTTP headers are sent via the Symfony
     * Response, so ZipStream must not send its own.
     */
    private function openZipStream(OperationMode $operationMode = OperationMode::NORMAL, $outputStream = null): ZipStream
    {
        return new ZipStream(
            operationMode: $operationMode,
            outputStream: $outputStream,
            sendHttpHeaders: false,
            defaultCompressionMethod: CompressionMethod::STORE,
            flushOutput: true,
        );
    }

    /**
     * Computes the exact byte size of the ZIP up front so we can send a Content-Length and the
     * browser shows a real download progress bar. Uses ZipStream's "simulate" pass, which only
     * stats each file (no contents are read); the result is byte-exact because we pack with
     * STORE. Best effort: any hiccup returns null and the download simply streams without a
     * progress bar. Note: the size is taken just before the download starts - if a file changes
     * size during the (possibly long) download, the archive may end up truncated; just retry.
     */
    private function computeZipSize(?Backup $backup, string $manifest): ?int
    {
        if (!$this->simulationFitsInMemory()) {
            return null;
        }

        $sink = fopen('php://temp', 'w+b');

        if (!\is_resource($sink)) {
            return null;
        }

        $placeholder = null;

        try {
            $zip = $this->openZipStream(OperationMode::SIMULATE_LAX, $sink);
            $zip->addFile(self::MANIFEST_NAME, $manifest);

            if (null !== $backup) {
                // Without a reliable DB size we cannot predict the exact ZIP size; better no
                // Content-Length (no progress bar) than a wrong one that truncates the download.
                if ($backup->getSize() <= 0) {
                    $this->logProgressBarDisabled('the database backup size could not be determined');

                    return null;
                }

                // The DB stream's size is known (getSize), so the placeholder is never read.
                $placeholder = fopen('php://temp', 'rb');
                $zip->addFileFromStream(
                    'database/'.$backup->getFilename(),
                    $placeholder,
                    exactSize: $backup->getSize(),
                );
            }

            $this->addProjectFiles($zip);
            $size = $zip->finish();

            if ($size <= 0) {
                $this->logProgressBarDisabled('the computed ZIP size was zero');

                return null;
            }

            return $size;
        } catch (\Throwable $e) {
            $this->logProgressBarDisabled('the ZIP size could not be computed up front: '.$e->getMessage(), $e);

            return null;
        } finally {
            if (\is_resource($placeholder)) {
                fclose($placeholder);
            }

            fclose($sink);
        }
    }

    /**
     * Guards the size computation above against running out of memory.
     *
     * Unlike the streaming pass (which finalises every entry immediately and stays flat),
     * ZipStream's simulate pass holds one object per file until finish(); measured on a site
     * with 34k files that is about 4 KB each, so roughly 140 MB - more than the common 128M
     * limit. And a memory error is a PHP FATAL, not a catchable Throwable: without this check
     * the whole request dies with "Internal Server Error" instead of falling back to a
     * download without a progress bar. Hence: count the files first (constant memory) and
     * only simulate when the estimate comfortably fits.
     */
    private function simulationFitsInMemory(): bool
    {
        $limit = $this->memoryLimitInBytes();

        if (null === $limit) {
            return true;
        }

        $available = $limit - memory_get_usage(true);
        $files = iterator_count($this->projectFiles());
        $needed = $files * self::SIMULATE_BYTES_PER_FILE;

        if ($needed <= $available * self::SIMULATE_MEMORY_SHARE) {
            return true;
        }

        $this->logProgressBarDisabled(sprintf(
            'simulating the archive for %d files would need about %d MiB, but only %d MiB of the %d MiB memory limit are available',
            $files,
            (int) round($needed / 1024 / 1024),
            (int) round($available / 1024 / 1024),
            (int) round($limit / 1024 / 1024),
        ));

        return false;
    }

    /**
     * The memory_limit in bytes, or null when there is none (-1) and nothing to guard against.
     */
    private function memoryLimitInBytes(): ?int
    {
        $limit = trim((string) \ini_get('memory_limit'));

        if ('' === $limit || '-1' === $limit) {
            return null;
        }

        $value = (int) $limit;

        return match (strtoupper(substr($limit, -1))) {
            'G' => $value * 1024 * 1024 * 1024,
            'M' => $value * 1024 * 1024,
            'K' => $value * 1024,
            default => $value,
        };
    }

    /**
     * @param callable():void|null $afterEachFile called after every packed file, e.g. to
     *                                            report progress on a long-running store
     */
    private function addProjectFiles(ZipStream $zip, callable|null $afterEachFile = null): void
    {
        foreach ($this->projectFiles() as $localPath => $absolutePath) {
            $zip->addFileFromPath($localPath, $absolutePath);

            if (null !== $afterEachFile) {
                $afterEachFile();
            }
        }
    }

    /**
     * Every file that goes into the archive, as localPath => absolutePath. A generator, so
     * walking it costs constant memory no matter how many files the site has - and so the
     * up-front count cannot drift from what actually gets packed.
     */
    private function projectFiles(): \Generator
    {
        foreach (self::PATHS as $relativePath) {
            $absolutePath = $this->projectDir.'/'.$relativePath;

            if (is_file($absolutePath)) {
                yield $relativePath => $absolutePath;

                continue;
            }

            if (!is_dir($absolutePath)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absolutePath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($iterator as $item) {
                // Never follow symlinks: they could point outside the project (e.g. a planted
                // files/link -> /etc/passwd) and have no place in a site backup.
                if ($item->isLink() || !$item->isFile() || !$item->isReadable()) {
                    continue;
                }

                yield $relativePath.'/'.substr($item->getPathname(), \strlen($absolutePath) + 1) => $item->getPathname();
            }
        }
    }

    private function prepareZipResponse(StreamedResponse $response, string $filename, ?int $size = null): Response
    {
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
        );

        // A known length lets the browser show a real download progress bar. Without it the
        // download still works, just without progress.
        if (null !== $size) {
            $response->headers->set('Content-Length', (string) $size);

            // Same value in a custom header: some servers/proxies drop Content-Length on a
            // streamed (chunked) response, which leaves the on-page progress bar without a total.
            // A custom header is passed through untouched, so the bar still shows exact percentages.
            $response->headers->set('X-Backup-Size', (string) $size);
        }

        // Tell nginx not to buffer the response, otherwise it would collect the whole ZIP
        // before sending - defeating the streaming and re-introducing the timeout.
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    /**
     * Discard any active output buffers so the binary stream is written straight to the
     * client instead of piling up in memory (and so stray output cannot corrupt the ZIP).
     */
    private function flushOutputBuffers(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    /**
     * The download itself still works without a Content-Length - only the on-page progress bar
     * is disabled. Log the reason (Contao log + var/logs) so it is no longer swallowed silently.
     */
    private function logProgressBarDisabled(string $reason, ?\Throwable $e = null): void
    {
        $this->logger->error(
            'Backup: on-page progress bar disabled (the download itself still works) - '.$reason.'.',
            array_filter([
                'contao' => new ContaoContext(self::class.'::computeZipSize', ContaoContext::ERROR),
                'exception' => $e,
            ]),
        );
    }

    /**
     * A tiny streamed response that lets the front end detect, before any real download, whether
     * the size headers survive a streamed response. A compressing proxy (e.g. Apache mod_deflate)
     * strips Content-Length on streamed (chunked) responses, so the browser cannot show a
     * percentage; this probe carries the same size headers as a real download so the JavaScript
     * can test for that and show a hint.
     */
    public function createProbeResponse(): Response
    {
        $size = 32768;

        $response = new StreamedResponse(function () use ($size): void {
            $this->flushOutputBuffers();

            $chunk = str_repeat('0', 8192);

            for ($sent = 0; $sent < $size; $sent += 8192) {
                echo $chunk;
                flush();
            }
        });

        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Content-Length', (string) $size);
        $response->headers->set('X-Backup-Size', (string) $size);
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * Builds a download name like "full-backup_example_com_20260622205726.zip": the prefix, the
     * current host (dots and other separators turned into underscores) and a timestamp.
     */
    private function filename(string $prefix, string $extension): string
    {
        $host = $this->requestStack->getCurrentRequest()?->getHost() ?? '';
        $host = preg_replace('/^www\./i', '', $host);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/i', '_', (string) $host), '_');

        return $prefix.'_'.('' !== $slug ? $slug.'_' : '').date('YmdHis').$extension;
    }

    private function liftTimeLimit(): void
    {
        if (\function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
    }
}
