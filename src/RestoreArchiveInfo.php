<?php

declare(strict_types=1);

namespace Heimseiten\ContaoBackupBundle;

/**
 * Result of analyzing an uploaded backup archive: what is inside and how big it is.
 */
final class RestoreArchiveInfo
{
    public const COMPOSER_PATHS = ['composer.json', 'composer.lock'];

    public function __construct(
        /** Original name of the uploaded file (display only). */
        public readonly string $displayName,
        /** Size of the ZIP file in bytes. */
        public readonly int $archiveSize,
        /** ZIP entry name of the database dump (e.g. "database/backup__20260101120000.sql.gz") or null. */
        public readonly string|null $databaseEntry,
        /** Creation time parsed from the dump's file name, or null. */
        public readonly \DateTimeImmutable|null $databaseCreatedAt,
        /** Restorable top-level paths => number of files (e.g. ['files' => 123, 'composer.json' => 1]). */
        public readonly array $paths,
        /** Total number of restorable file entries. */
        public readonly int $fileCount,
        /** Total uncompressed size of the restorable file entries in bytes. */
        public readonly int $uncompressedBytes,
        /** Entries outside the known backup paths (ignored on restore). */
        public readonly int $ignoredCount,
        /** Symlink entries (never extracted). */
        public readonly int $symlinkCount,
    ) {
    }

    public function hasDatabase(): bool
    {
        return null !== $this->databaseEntry;
    }

    /**
     * @return list<string> the composer.json/composer.lock paths present in the archive
     */
    public function composerPaths(): array
    {
        return array_values(array_intersect(self::COMPOSER_PATHS, array_keys($this->paths)));
    }

    /**
     * @return list<string> restorable paths without composer.json/composer.lock
     */
    public function nonComposerPaths(): array
    {
        return array_values(array_diff(array_keys($this->paths), self::COMPOSER_PATHS));
    }

    public function hasFiles(): bool
    {
        return [] !== $this->paths;
    }
}
