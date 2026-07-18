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

    private function addProjectFiles(ZipStream $zip): void
    {
        foreach (self::PATHS as $relativePath) {
            $absolutePath = $this->projectDir.'/'.$relativePath;

            if (is_file($absolutePath)) {
                $zip->addFileFromPath($relativePath, $absolutePath);
            } elseif (is_dir($absolutePath)) {
                $this->addDirectory($zip, $absolutePath, $relativePath);
            }
        }
    }

    private function addDirectory(ZipStream $zip, string $absoluteDir, string $relativeDir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absoluteDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            // Never follow symlinks: they could point outside the project (e.g. a planted
            // files/link -> /etc/passwd) and have no place in a site backup.
            if ($item->isLink()) {
                continue;
            }

            if ($item->isFile() && $item->isReadable()) {
                $localPath = $relativeDir.'/'.substr($item->getPathname(), \strlen($absoluteDir) + 1);
                $zip->addFileFromPath($localPath, $item->getPathname());
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
