<?php

declare(strict_types=1);

namespace Heimseiten\ContaoBackupBundle;

use Contao\CoreBundle\Doctrine\Backup\Backup;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Manages the backup archive to be restored: either an upload below var/backup_restore
 * (outside the web root) or an archive that was placed on the server by other means (FTP,
 * SSH, the hosting file manager) and merely selected in the back end. Handles the
 * chunked/direct uploads, validation and analysis of the ZIP, and the staging directory
 * the archive is extracted to before the atomic path swap.
 */
final class RestoreArchiveStore
{
    /**
     * The archive entry name of the database dump: "database/<contao backup name>".
     */
    private const DATABASE_ENTRY_REGEX = '@^database/[^/]*__(\d{14})\.sql(\.gz)?$@';

    /**
     * Project-relative directories that are scanned for archives placed on the server
     * without the back-end upload. Both are outside the web root, so an archive sitting
     * there can never be fetched over HTTP - which is why files/ is deliberately NOT
     * among them (its folders can be published).
     */
    private const SERVER_ARCHIVE_DIRS = ['var/backups', 'var/backup_restore'];

    /**
     * The manifest is read fully into memory, so its size is capped (1 MiB is far more
     * than the real ~10-50 KB manifest ever needs).
     */
    private const MAX_MANIFEST_BYTES = 1048576;

    public function __construct(private readonly string $projectDir)
    {
    }

    public function directory(): string
    {
        return $this->projectDir.'/var/backup_restore';
    }

    /**
     * The archive currently staged for a restore: the selected server file if one is
     * chosen, otherwise the uploaded one.
     */
    public function archivePath(): string
    {
        return $this->selectedServerArchive() ?? $this->uploadPath();
    }

    /**
     * Where an upload is stored. Always inside var/backup_restore, never the path of a
     * selected server file (which the bundle only ever reads, never writes or deletes
     * behind the user's back).
     */
    public function uploadPath(): string
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
     * The file name shown for the staged archive: the real name of a selected server
     * file, otherwise the original name of the upload (stored next to the archive).
     */
    public function archiveDisplayName(): string
    {
        $selected = $this->selectedServerArchive();
        $nameFile = $this->uploadPath().'.name';
        $name = null !== $selected ? basename($selected) : (is_file($nameFile) ? trim((string) file_get_contents($nameFile)) : '');

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
            if (\is_resource($source)) {
                fclose($source);
            }

            if (\is_resource($target)) {
                fclose($target);
            }

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
        $this->rename($part, $this->uploadPath());
        file_put_contents($this->uploadPath().'.name', $originalName);
    }

    /**
     * Stores a classic (non-chunked) form upload as the archive.
     */
    public function storeUploadedFile(UploadedFile $file): void
    {
        $this->ensureDirectory();
        $this->discardArchive();

        $originalName = $file->getClientOriginalName();
        $file->move($this->directory(), basename($this->uploadPath()));
        file_put_contents($this->uploadPath().'.name', $originalName);
    }

    /**
     * Clears the staged archive and all working data (staging, dump copy, partial uploads).
     * An uploaded archive is deleted; a SELECTED server file is only deselected - it was
     * put there by the user and stays available for another attempt.
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

        // A selection whose file has meanwhile been removed (e.g. per FTP) is stale.
        if (is_file($this->selectionPath()) && null === $this->selectedServerArchive()) {
            (new Filesystem())->remove($this->selectionPath());
        }
    }

    /**
     * The directories an archive may be placed in, as project-relative paths - shown in
     * the back end so it is clear where to put the file. var/backup_restore is created on
     * demand, var/backups always exists (Contao's own backup directory).
     *
     * @return list<string>
     */
    public function serverArchiveDirectories(): array
    {
        return self::SERVER_ARCHIVE_DIRS;
    }

    /**
     * The ZIP archives lying in the scanned directories, newest first - the choices for a
     * restore without an upload. The bundle's own working files (the upload and the
     * partial chunks) are never offered.
     *
     * Each entry carries a short "key" derived from its path: that key - not the path
     * itself - is what the back-end form posts, so no file name ever has to survive
     * Contao's input filtering, and the server resolves it against this very list.
     *
     * @return list<array{key: string, path: string, name: string, size: int, modified: int}>
     */
    public function listServerArchives(): array
    {
        $archives = [];

        foreach (self::SERVER_ARCHIVE_DIRS as $relativeDir) {
            $realDir = realpath($this->projectDir.'/'.$relativeDir);

            if (false === $realDir || !is_dir($realDir)) {
                continue;
            }

            foreach (glob($realDir.'/*.[zZ][iI][pP]') ?: [] as $file) {
                // Resolve the file too: a symlink pointing out of the directory (or at a
                // device/socket) must never become a restore source.
                $realFile = realpath($file);

                if (false === $realFile || !is_file($realFile) || \dirname($realFile) !== $realDir) {
                    continue;
                }

                // Skip our own working copy of an upload in progress.
                if ($realFile === realpath($this->uploadPath())) {
                    continue;
                }

                $archives[] = [
                    'key' => substr(sha1($relativeDir.'/'.basename($realFile)), 0, 16),
                    'path' => $relativeDir.'/'.basename($realFile),
                    'name' => basename($realFile),
                    'size' => (int) filesize($realFile),
                    'modified' => (int) filemtime($realFile),
                ];
            }
        }

        usort($archives, static fn (array $a, array $b): int => $b['modified'] <=> $a['modified']);

        return $archives;
    }

    /**
     * Stages an archive that already lies on the server. The file is NOT copied or moved -
     * a multi-GB archive would otherwise need the space twice, and discarding it would
     * destroy what the user uploaded per FTP - it is only remembered and read in place.
     *
     * @param string $key the "key" of one of the entries of listServerArchives()
     *
     * @throws RestoreException if the key does not belong to an offered archive
     */
    public function selectServerArchive(string $key): void
    {
        // Resolving the key against the freshly built list IS the validation: nothing is
        // derived from the posted string, so no traversal or symlink trick can widen the
        // choice beyond the files actually lying in the scanned directories.
        $archive = $this->findServerArchive($key);

        if (null === $archive) {
            throw new RestoreException('The selected file is not one of the backup archives on the server.');
        }

        $this->ensureDirectory();
        $this->discardArchive();

        if (false === @file_put_contents($this->selectionPath(), $archive['path'])) {
            throw new RestoreException(\sprintf('Could not write the selection to "%s" - is var/backup_restore writable?', $this->selectionPath()));
        }
    }

    /**
     * Deletes one of the offered archives from the server (explicit user action, so a
     * large archive need not be removed per FTP afterwards).
     *
     * @param string $key the "key" of one of the entries of listServerArchives()
     *
     * @throws RestoreException if the key does not belong to an offered archive
     */
    public function deleteServerArchive(string $key): string
    {
        $archive = $this->findServerArchive($key);

        if (null === $archive) {
            throw new RestoreException('The file to delete is not one of the backup archives on the server.');
        }

        if ($archive['path'] === $this->selectedRelativePath()) {
            (new Filesystem())->remove($this->selectionPath());
        }

        try {
            (new Filesystem())->remove($this->projectDir.'/'.$archive['path']);
        } catch (\Throwable $t) {
            throw new RestoreException(\sprintf('Could not delete "%s": %s', $archive['path'], $t->getMessage()), 0, $t);
        }

        return $archive['name'];
    }

    /**
     * @return array{key: string, path: string, name: string, size: int, modified: int}|null
     */
    private function findServerArchive(string $key): array|null
    {
        foreach ($this->listServerArchives() as $archive) {
            if ($archive['key'] === $key) {
                return $archive;
            }
        }

        return null;
    }

    /**
     * True if the staged archive is a selected server file rather than an upload.
     */
    public function isServerArchiveSelected(): bool
    {
        return null !== $this->selectedServerArchive();
    }

    /**
     * The project-relative path of the selected server file (for display), or null.
     */
    public function selectedArchiveRelativePath(): string|null
    {
        return null !== $this->selectedServerArchive() ? $this->selectedRelativePath() : null;
    }

    /**
     * The absolute path of the selected server file, or null if nothing is selected (or
     * the selection has become invalid because the file was removed meanwhile).
     */
    private function selectedServerArchive(): string|null
    {
        $relativePath = $this->selectedRelativePath();

        if (null === $relativePath) {
            return null;
        }

        $realFile = realpath($this->projectDir.'/'.$relativePath);

        // Re-validate on every access: the pointer file is plain text on disk, so its
        // target is checked against the allowed directories again, not trusted.
        if (false === $realFile || !is_file($realFile)) {
            return null;
        }

        foreach (self::SERVER_ARCHIVE_DIRS as $relativeDir) {
            $realDir = realpath($this->projectDir.'/'.$relativeDir);

            if (false !== $realDir && \dirname($realFile) === $realDir) {
                return $realFile;
            }
        }

        return null;
    }

    /**
     * The raw pointer content, restricted to a plausible "<dir>/<name>.zip" shape.
     */
    private function selectedRelativePath(): string|null
    {
        if (!is_file($this->selectionPath())) {
            return null;
        }

        $relativePath = trim((string) file_get_contents($this->selectionPath()));

        if ('' === $relativePath || $this->isUnsafeEntryName($relativePath) || !preg_match('/\.zip$/i', $relativePath)) {
            return null;
        }

        return $relativePath;
    }

    /**
     * Pointer file holding the project-relative path of the selected server archive.
     */
    private function selectionPath(): string
    {
        return $this->directory().'/selected-archive';
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
                // Read the manifest fully into memory - cap the size so a maliciously huge
                // manifest entry cannot exhaust the memory limit.
                if ((int) $stat['size'] <= self::MAX_MANIFEST_BYTES) {
                    $decoded = json_decode((string) $zip->getFromIndex($i), true);
                    $manifest = \is_array($decoded) ? $decoded : null;
                }

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
        // Accept both a crypto.randomUUID() and the plain-HTTP hex fallback. Letters/digits,
        // dashes and underscores only - no dot or slash, so the id can never traverse paths.
        if (!preg_match('/^[a-zA-Z0-9_-]{10,64}$/', $uploadId)) {
            throw new RestoreException('Invalid upload id.');
        }

        return $this->directory().'/upload-'.$uploadId.'.part';
    }

    private function discardArchive(): void
    {
        $fs = new Filesystem();
        $fs->remove($this->uploadPath());
        $fs->remove($this->uploadPath().'.name');
        $fs->remove($this->selectionPath());
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
     * True if an entry name could escape the extraction directory (Zip Slip): parent
     * segments, absolute paths, backslashes, drive letters or control characters.
     * Public so the extraction step can re-check every entry right before writing it
     * (defense in depth: the archive on disk could have been swapped after analyze()).
     */
    public function isUnsafeEntryName(string $name): bool
    {
        return str_contains($name, '\\')
            || str_starts_with($name, '/')
            || (bool) preg_match('/(?:^|\/)\.\.(?:\/|$)/', $name)
            || (bool) preg_match('/^[a-zA-Z]:/', $name)
            || (bool) preg_match('/[\x00-\x1f]/', $name);
    }

    /**
     * Rejects entry names that could escape the extraction directory (Zip Slip). One bad
     * entry rejects the whole archive - a manipulated backup must not be half-restored.
     */
    private function assertSafeEntryName(string $name, \ZipArchive $zip): void
    {
        if ($this->isUnsafeEntryName($name)) {
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
