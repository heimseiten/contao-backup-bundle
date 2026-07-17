<?php

declare(strict_types=1);

namespace Heimseiten\ContaoBackupBundle;

use Contao\CoreBundle\Doctrine\Backup\Backup;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Manages the uploaded backup archive below var/backup_restore (outside the web root):
 * chunked/direct uploads, validation and analysis of the ZIP, and the staging directory
 * the archive is extracted to before the atomic path swap.
 */
final class RestoreArchiveStore
{
    /**
     * The archive entry name of the database dump: "database/<contao backup name>".
     */
    private const DATABASE_ENTRY_REGEX = '@^database/[^/]*__(\d{14})\.sql(\.gz)?$@';

    public function __construct(private readonly string $projectDir)
    {
    }

    public function directory(): string
    {
        return $this->projectDir.'/var/backup_restore';
    }

    public function archivePath(): string
    {
        return $this->directory().'/upload.zip';
    }

    public function stagingDir(): string
    {
        return $this->directory().'/staging';
    }

    /**
     * Local working copy of the database dump about to be restored (not in var/backups,
     * so Contao's retention policy can never delete it mid-restore).
     */
    public function databaseWorkPath(): string
    {
        return $this->directory().'/database-restore.dump';
    }

    public function hasArchive(): bool
    {
        return is_file($this->archivePath());
    }

    /**
     * The original file name of the upload (display only, stored next to the archive).
     */
    public function archiveDisplayName(): string
    {
        $nameFile = $this->archivePath().'.name';
        $name = is_file($nameFile) ? trim((string) file_get_contents($nameFile)) : '';

        // Sanitize for display: the name is user input.
        $name = preg_replace('/[^\w.\- ()\[\]]/u', '_', $name) ?? '';

        return '' !== $name ? $name : 'upload.zip';
    }

    /**
     * Appends one chunk to the partial upload. The client sends its current offset; if it
     * does not match the bytes we already have, the mismatch is reported so the client can
     * fail cleanly (a re-sent chunk that is already complete is acknowledged idempotently).
     *
     * @return array{size: int} the new partial size
     *
     * @throws RestoreException on an offset mismatch
     */
    public function appendChunk(string $uploadId, int $offset, string $chunkFile): array
    {
        $this->ensureDirectory();

        $part = $this->partPath($uploadId);
        $currentSize = is_file($part) ? (int) filesize($part) : 0;
        $chunkSize = (int) filesize($chunkFile);

        // A fresh upload replaces whatever archive was there before.
        if (0 === $offset) {
            $this->discardArchive();

            if ($currentSize > 0) {
                (new Filesystem())->remove($part);
                $currentSize = 0;
            }
        }

        // Retry of a chunk we already have: acknowledge without writing.
        if ($offset < $currentSize && $offset + $chunkSize === $currentSize) {
            return ['size' => $currentSize];
        }

        if ($offset !== $currentSize) {
            throw new RestoreException(\sprintf('Chunk offset mismatch (expected %d, got %d) - please restart the upload.', $currentSize, $offset));
        }

        $source = fopen($chunkFile, 'rb');
        $target = fopen($part, 'ab');

        if (!\is_resource($source) || !\is_resource($target)) {
            throw new RestoreException('Could not open the chunk or the partial upload file for writing.');
        }

        stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);

        return ['size' => (int) filesize($part)];
    }

    /**
     * Turns the completed partial upload into the archive (after verifying the total size).
     */
    public function finalizeChunked(string $uploadId, int $expectedSize, string $originalName): void
    {
        $part = $this->partPath($uploadId);
        $size = is_file($part) ? (int) filesize($part) : 0;

        if ($size < 1 || $size !== $expectedSize) {
            (new Filesystem())->remove($part);

            throw new RestoreException(\sprintf('Upload incomplete (%d of %d bytes) - please restart the upload.', $size, $expectedSize));
        }

        $this->discardArchive();
        $this->rename($part, $this->archivePath());
        file_put_contents($this->archivePath().'.name', $originalName);
    }

    /**
     * Stores a classic (non-chunked) form upload as the archive.
     */
    public function storeUploadedFile(UploadedFile $file): void
    {
        $this->ensureDirectory();
        $this->discardArchive();

        $originalName = $file->getClientOriginalName();
        $file->move($this->directory(), 'upload.zip');
        file_put_contents($this->archivePath().'.name', $originalName);
    }

    /**
     * Removes the uploaded archive and all working data (staging, dump copy, partial uploads).
     */
    public function discard(): void
    {
        $fs = new Filesystem();

        $this->discardArchive();
        $fs->remove($this->stagingDir());
        $fs->remove($this->databaseWorkPath());

        foreach (glob($this->directory().'/upload-*.part') ?: [] as $part) {
            $fs->remove($part);
        }
    }

    /**
     * Removes partial uploads that were abandoned more than a day ago.
     */
    public function collectGarbage(): void
    {
        foreach (glob($this->directory().'/upload-*.part') ?: [] as $part) {
            if (is_file($part) && filemtime($part) < time() - 86400) {
                (new Filesystem())->remove($part);
            }
        }
    }

    /**
     * Opens and analyzes the uploaded archive: which known backup paths and which database
     * dump it contains. Rejects archives with unsafe entry names (path traversal), so
     * everything after this method can trust the entry list.
     *
     * @throws RestoreException if there is no archive, it cannot be read, or it is unsafe
     */
    public function analyze(): RestoreArchiveInfo
    {
        if (!$this->hasArchive()) {
            throw new RestoreException('No uploaded archive found.');
        }

        $zip = $this->openArchive();

        $databaseEntry = null;
        $databaseCreatedAt = null;
        $paths = [];
        $fileCount = 0;
        $uncompressed = 0;
        $ignored = 0;
        $symlinks = 0;
        $manifest = null;

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $stat = $zip->statIndex($i);

            if (false === $stat) {
                continue;
            }

            $name = (string) $stat['name'];
            $this->assertSafeEntryName($name, $zip);

            if (str_ends_with($name, '/')) {
                continue; // directory entry
            }

            if ($this->isSymlinkEntry($zip, $i)) {
                ++$symlinks;
                continue;
            }

            // Version metadata of the source installation (never extracted to disk).
            if (BackupDownloader::MANIFEST_NAME === $name) {
                $decoded = json_decode((string) $zip->getFromIndex($i), true);
                $manifest = \is_array($decoded) ? $decoded : null;
                continue;
            }

            // The database dump lives under database/ and keeps Contao's backup name.
            if (preg_match(self::DATABASE_ENTRY_REGEX, $name, $matches)) {
                // Should there be several dumps, use the newest one.
                if (null === $databaseEntry || strcmp($name, $databaseEntry) > 0) {
                    $databaseEntry = $name;
                    $databaseCreatedAt = \DateTimeImmutable::createFromFormat(
                        Backup::DATETIME_FORMAT,
                        $matches[1],
                        new \DateTimeZone('UTC'),
                    ) ?: null;
                }

                continue;
            }

            $root = $this->matchBackupPath($name);

            if (null === $root) {
                ++$ignored; // not one of the known backup paths - never restored
                continue;
            }

            $paths[$root] = ($paths[$root] ?? 0) + 1;
            ++$fileCount;
            $uncompressed += (int) $stat['size'];
        }

        $zip->close();

        ksort($paths);

        return new RestoreArchiveInfo(
            $this->archiveDisplayName(),
            (int) filesize($this->archivePath()),
            $databaseEntry,
            $databaseCreatedAt,
            $paths,
            $fileCount,
            $uncompressed,
            $ignored,
            $symlinks,
            $manifest,
        );
    }

    /**
     * @throws RestoreException if the archive cannot be opened as a ZIP
     */
    public function openArchive(): \ZipArchive
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new RestoreException('The PHP "zip" extension is required to restore an uploaded archive.');
        }

        $zip = new \ZipArchive();
        $error = $zip->open($this->archivePath(), \ZipArchive::RDONLY);

        if (true !== $error) {
            throw new RestoreException(\sprintf('The uploaded file could not be opened as a ZIP archive (code %s).', var_export($error, true)));
        }

        return $zip;
    }

    /**
     * Maps an archive entry to the backup path it belongs to ("files/foo.jpg" -> "files"),
     * or null if it is outside all known backup paths.
     */
    public function matchBackupPath(string $entryName): string|null
    {
        foreach (BackupDownloader::PATHS as $path) {
            if ($entryName === $path || str_starts_with($entryName, $path.'/')) {
                return $path;
            }
        }

        return null;
    }

    public function clearStaging(): void
    {
        (new Filesystem())->remove($this->stagingDir());
        (new Filesystem())->mkdir($this->stagingDir());
    }

    private function partPath(string $uploadId): string
    {
        if (!preg_match('/^[a-f0-9-]{10,64}$/', $uploadId)) {
            throw new RestoreException('Invalid upload id.');
        }

        return $this->directory().'/upload-'.$uploadId.'.part';
    }

    private function discardArchive(): void
    {
        $fs = new Filesystem();
        $fs->remove($this->archivePath());
        $fs->remove($this->archivePath().'.name');
    }

    private function ensureDirectory(): void
    {
        (new Filesystem())->mkdir($this->directory());
    }

    private function rename(string $from, string $to): void
    {
        if (!@rename($from, $to)) {
            throw new RestoreException(\sprintf('Could not move "%s" to "%s".', $from, $to));
        }
    }

    /**
     * Rejects entry names that could escape the extraction directory (Zip Slip): parent
     * segments, absolute paths, backslashes, drive letters or control characters. One bad
     * entry rejects the whole archive - a manipulated backup must not be half-restored.
     */
    private function assertSafeEntryName(string $name, \ZipArchive $zip): void
    {
        $unsafe = str_contains($name, '\\')
            || str_starts_with($name, '/')
            || preg_match('/(?:^|\/)\.\.(?:\/|$)/', $name)
            || preg_match('/^[a-zA-Z]:/', $name)
            || preg_match('/[\x00-\x1f]/', $name);

        if ($unsafe) {
            $zip->close();

            throw new RestoreException(\sprintf('The archive contains an unsafe entry name ("%s") and was rejected.', $name));
        }
    }

    /**
     * True if the entry was stored as a symbolic link (Unix external attributes).
     * Symlinks are never extracted: a backup created by this bundle never contains any,
     * and restoring one could redirect later writes outside the project directory.
     */
    public function isSymlinkEntry(\ZipArchive $zip, int $index): bool
    {
        $opsys = 0;
        $attr = 0;

        if (!$zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            return false;
        }

        return \ZipArchive::OPSYS_UNIX === $opsys && 0xA000 === (($attr >> 16) & 0xF000);
    }
}
