<?php

declare(strict_types=1);

namespace Heimseiten\ContaoBackupBundle;

/**
 * What a restore run should do. Populated from the (server-validated) form input.
 */
final class RestoreOptions
{
    public function __construct(
        public readonly bool $restoreDatabase,
        public readonly bool $restoreFiles,
        /** Also restore composer.json/composer.lock (only relevant with $restoreFiles). */
        public readonly bool $includeComposer,
        /** Create a database backup of the current state before touching anything. */
        public readonly bool $safetyBackup,
        /** Run the DBAFS synchronization (contao:filesync) afterwards. */
        public readonly bool $runFilesync,
        /** Run contao:migrate (no deletes) after the database restore. */
        public readonly bool $runMigrations = false,
        /** Proceed although the backup stems from a NEWER Contao version (dangerous). */
        public readonly bool $allowDowngrade = false,
    ) {
    }
}
