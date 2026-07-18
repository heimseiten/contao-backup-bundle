<?php

declare(strict_types=1);

namespace Heimseiten\ContaoBackupBundle;

use Composer\InstalledVersions;
use Contao\CoreBundle\Doctrine\Backup\Backup;
use Contao\CoreBundle\Doctrine\Backup\BackupManager;
use Contao\CoreBundle\Doctrine\Backup\Config\RestoreConfig;
use Contao\CoreBundle\Filesystem\Dbafs\DbafsManager;
use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use Contao\CoreBundle\Monolog\ContaoContext;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Restores a backup: replaces the database (all tables are dropped first, so nothing
 * survives that is not part of the dump) and/or the backed-up files and folders (replaced
 * via an atomic rename swap with rollback). Sources are either a database backup already
 * in var/backups or an uploaded backup archive (see RestoreArchiveStore).
 *
 * Order of operations is chosen so that everything destructive happens as late as
 * possible: upload analysis, extraction and the safety backup all run before the first
 * table is dropped or the first path is swapped.
 */
final class BackupRestorer
{
    /**
     * Extra disk space (bytes) required on top of the uncompressed archive size.
     */
    private const DISK_SPACE_MARGIN = 64 * 1024 * 1024;

    public function __construct(
        private readonly BackupManager $backupManager,
        private readonly VirtualFilesystemInterface $backupsStorage,
        private readonly Connection $connection,
        private readonly RestoreArchiveStore $store,
        private readonly DbafsManager $dbafsManager,
        private readonly KernelInterface $kernel,
        private readonly string $projectDir,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The Contao version of THIS installation (used for the compatibility check).
     */
    public function installedContaoVersion(): string|null
    {
        try {
            return InstalledVersions::getPrettyVersion('contao/core-bundle');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * True if the backup stems from a NEWER Contao feature version than this
     * installation runs - restoring it would leave a database schema the older code
     * cannot handle (there is no downgrade migration).
     *
     * ADVISORY ONLY, not a security boundary: the source version comes from the manifest
     * inside the uploaded archive, which the uploader controls (it can be missing or
     * edited). This guards against an honest mistake, not a determined admin - which is
     * fine, because an admin may legitimately restore anything anyway.
     */
    public function isDowngrade(string|null $sourceVersion): bool
    {
        $target = $this->installedContaoVersion();

        if (null === $sourceVersion || null === $target) {
            return false; // unknown - cannot judge
        }

        return version_compare($this->featureVersion($sourceVersion), $this->featureVersion($target), '>');
    }

    /**
     * Reduces "5.3.48" to the feature version "5.3" (patch differences are harmless).
     */
    private function featureVersion(string $version): string
    {
        return preg_match('/^(\d+\.\d+)/', $version, $matches) ? $matches[1] : $version;
    }

    /**
     * The database backups in var/backups (newest first), for the selection list.
     *
     * @return list<Backup>
     */
    public function listServerBackups(): array
    {
        return $this->backupManager->listBackups();
    }

    /**
     * Restores a database backup that already lives in var/backups.
     *
     * @throws RestoreException
     */
    public function restoreFromServerBackup(string $backupName, RestoreOptions $options): RestoreResult
    {
        $lock = $this->acquireLock();
        $safetyBackupName = null;

        try {
            $backup = $this->backupManager->getBackupByName($backupName);

            if (null === $backup) {
                throw new RestoreException(\sprintf('Backup "%s" does not exist.', $backupName));
            }

            $this->log(\sprintf('Restore from server backup "%s" started', $backupName));

            $steps = [];
            $warnings = [];

            // Work on a copy outside var/backups FIRST: creating the safety backup below
            // triggers Contao's retention policy, which may delete the very backup that is
            // about to be restored.
            $workFile = $this->store->databaseWorkPath();
            $this->copyStreamToFile($this->backupManager->readStream($backup), $workFile);

            if ($options->safetyBackup) {
                $safetyBackupName = $this->createSafetyBackup();
                $steps[] = ['safety_backup', [$safetyBackupName]];
            }

            $dropped = $this->dropAllTables();
            $steps[] = ['tables_dropped', [$dropped]];

            $this->importDump($workFile, $backup->getCreatedAt(), str_ends_with($backupName, '.gz'));
            $steps[] = ['database_restored', [$backupName]];

            $this->clearCaches(false);
            $steps[] = ['caches_cleared', []];

            if ($options->runMigrations) {
                $this->runContaoMigrate($steps, $warnings);
            }

            if ($options->runFilesync) {
                $this->runFilesync($steps, $warnings);
            }

            (new Filesystem())->remove($workFile);

            $this->log(\sprintf('Restore from server backup "%s" completed', $backupName));

            return new RestoreResult($steps, $warnings, $safetyBackupName);
        } catch (\Throwable $t) {
            $this->log(\sprintf('Restore from server backup "%s" failed: %s', $backupName, $t->getMessage()), true);

            throw $this->asRestoreException($t, $safetyBackupName);
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * Restores the uploaded backup archive (database and/or files, see the options).
     *
     * @throws RestoreException
     */
    public function restoreFromUploadedArchive(RestoreOptions $options): RestoreResult
    {
        $lock = $this->acquireLock();
        $safetyBackupName = null;

        try {
            $info = $this->store->analyze();

            $restoreDatabase = $options->restoreDatabase && $info->hasDatabase();
            $pathsToRestore = $options->includeComposer ? array_keys($info->paths) : $info->nonComposerPaths();
            $restoreFiles = $options->restoreFiles && [] !== $pathsToRestore;

            if (!$restoreDatabase && !$restoreFiles) {
                throw new RestoreException('Nothing to restore: the selected parts are not contained in the archive.');
            }

            // Never silently restore a backup of a NEWER Contao feature version: the code of
            // this installation cannot handle the newer database schema and there is no
            // downgrade path. Can only be overridden explicitly.
            if ($restoreDatabase && !$options->allowDowngrade && $this->isDowngrade($info->sourceContaoVersion())) {
                throw new RestoreException(\sprintf('The backup was created with Contao %s but this installation runs %s - restoring would downgrade the database below its schema version.', (string) $info->sourceContaoVersion(), (string) $this->installedContaoVersion()));
            }

            $this->log(\sprintf('Restore from uploaded archive "%s" started', $info->displayName));

            $steps = [];
            $warnings = [];

            if ($restoreFiles) {
                $this->assertEnoughDiskSpace($info->uncompressedBytes);
            }

            // Extract everything BEFORE touching the live system, so a broken archive
            // aborts the restore while the installation is still untouched.
            $zip = $this->store->openArchive();

            try {
                if ($restoreDatabase) {
                    $this->extractDatabaseDump($zip, (string) $info->databaseEntry);
                }

                $stagedRoots = $restoreFiles ? $this->extractFiles($zip, $pathsToRestore) : [];
            } finally {
                $zip->close();
            }

            if ($options->safetyBackup) {
                $safetyBackupName = $this->createSafetyBackup();
                $steps[] = ['safety_backup', [$safetyBackupName]];
            }

            if ($restoreDatabase) {
                $dropped = $this->dropAllTables();
                $steps[] = ['tables_dropped', [$dropped]];

                $this->importDump(
                    $this->store->databaseWorkPath(),
                    $info->databaseCreatedAt,
                    str_ends_with((string) $info->databaseEntry, '.gz'),
                );
                $steps[] = ['database_restored', [basename((string) $info->databaseEntry)]];
            }

            if ($restoreFiles && [] !== $stagedRoots) {
                $this->swapPaths($stagedRoots);
                $steps[] = ['files_restored', [implode(', ', $stagedRoots)]];
            }

            $this->clearCaches($restoreFiles);
            $steps[] = ['caches_cleared', []];

            if ($restoreDatabase && $options->runMigrations) {
                $this->runContaoMigrate($steps, $warnings);
            }

            if ($options->runFilesync) {
                $this->runFilesync($steps, $warnings);
            }

            // The restored composer.lock is the exact package list of the source - show how
            // far vendor/ deviates, so the result page can point to "composer install".
            $composerDiff = \in_array('composer.lock', $stagedRoots ?? [], true) ? $this->computeComposerDiff() : null;

            // Success: remove the upload, the staging leftovers and the dump copy.
            $this->store->discard();

            $this->log(\sprintf('Restore from uploaded archive "%s" completed', $info->displayName));

            return new RestoreResult($steps, $warnings, $safetyBackupName, $composerDiff);
        } catch (\Throwable $t) {
            $this->log('Restore from uploaded archive failed: '.$t->getMessage(), true);

            throw $this->asRestoreException($t, $safetyBackupName);
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * Wraps any failure into a RestoreException carrying the safety backup name (if one
     * was already created), so the error page can point to the recovery anchor.
     */
    private function asRestoreException(\Throwable $t, string|null $safetyBackupName): RestoreException
    {
        $exception = $t instanceof RestoreException ? $t : new RestoreException($t->getMessage(), 0, $t);
        $exception->safetyBackupName ??= $safetyBackupName;

        return $exception;
    }

    /**
     * Creates a database backup of the CURRENT state so the restore can be undone.
     * Failing here aborts the whole restore before anything got destroyed.
     */
    private function createSafetyBackup(): string
    {
        try {
            $config = $this->backupManager->createCreateConfig();
            $this->backupManager->create($config);
        } catch (\Throwable $t) {
            throw new RestoreException('Could not create the safety backup: '.$t->getMessage(), 0, $t);
        }

        return $config->getBackup()->getFilename();
    }

    /**
     * Drops ALL tables (except those configured to be ignored by Contao's backups, which
     * are intentionally not part of any dump), so no table survives that is not part of
     * the backup - the restored database matches the backup exactly.
     */
    private function dropAllTables(): int
    {
        $tablesToIgnore = $this->backupManager->createCreateConfig()->getTablesToIgnore();
        $tables = array_diff($this->connection->createSchemaManager()->listTableNames(), $tablesToIgnore);

        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($tables as $table) {
                $this->connection->executeStatement('DROP TABLE IF EXISTS '.$this->connection->quoteIdentifier($table));
            }
        } finally {
            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }

        return \count($tables);
    }

    /**
     * Imports a dump file via Contao's own restore. The manager only restores files inside
     * var/backups, so the dump is placed there under a "restore__" working name (and
     * removed again afterwards). The original backup file is never touched.
     */
    private function importDump(string $localDumpFile, \DateTimeInterface|null $createdAt, bool $gzip): void
    {
        if (!is_file($localDumpFile)) {
            throw new RestoreException('The database dump to restore is missing.');
        }

        $stamp = ($createdAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(Backup::DATETIME_FORMAT);
        $workName = 'restore__'.$stamp.'.sql'.($gzip ? '.gz' : '');

        $stream = fopen($localDumpFile, 'rb');

        if (!\is_resource($stream)) {
            throw new RestoreException('Could not read the database dump to restore.');
        }

        $this->backupsStorage->writeStream($workName, $stream);

        if (\is_resource($stream)) {
            fclose($stream);
        }

        try {
            $this->backupManager->restore(new RestoreConfig(new Backup($workName)));
        } finally {
            try {
                $this->backupsStorage->delete($workName);
            } catch (\Throwable) {
                // Leaving the working copy behind is harmless.
            }
        }
    }

    /**
     * Extracts the database dump from the archive to the local working file.
     */
    private function extractDatabaseDump(\ZipArchive $zip, string $entryName): void
    {
        $source = $zip->getStream($entryName);

        if (!\is_resource($source)) {
            throw new RestoreException(\sprintf('Could not read "%s" from the archive.', $entryName));
        }

        $this->copyStreamToFile($source, $this->store->databaseWorkPath());
    }

    /**
     * Extracts all restorable file entries into the staging directory.
     *
     * @param list<string> $pathsToRestore the top-level backup paths to extract
     *
     * @return list<string> the top-level paths that were actually staged
     */
    private function extractFiles(\ZipArchive $zip, array $pathsToRestore): array
    {
        $this->store->clearStaging();

        $staging = $this->store->stagingDir();
        $fs = new Filesystem();
        $roots = [];

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $stat = $zip->statIndex($i);

            if (false === $stat) {
                continue;
            }

            $name = (string) $stat['name'];

            // Re-check every entry name right before writing it (defense in depth): analyze()
            // already validated the archive, but it is a separate open() on the on-disk file,
            // which a concurrent upload could have swapped in between. One bad entry aborts.
            if ($this->store->isUnsafeEntryName($name)) {
                throw new RestoreException(\sprintf('The archive contains an unsafe entry name ("%s") and was rejected.', $name));
            }

            $root = $this->store->matchBackupPath(rtrim($name, '/'));

            if (null === $root || !\in_array($root, $pathsToRestore, true)) {
                continue;
            }

            // Directory entries (only present in hand-made archives) just create the folder.
            if (str_ends_with($name, '/')) {
                $fs->mkdir($staging.'/'.rtrim($name, '/'));
                $roots[$root] = true;
                continue;
            }

            if ($this->store->isSymlinkEntry($zip, $i)) {
                continue;
            }

            $source = $zip->getStreamIndex($i);

            if (!\is_resource($source)) {
                throw new RestoreException(\sprintf('Could not read "%s" from the archive.', $name));
            }

            $this->copyStreamToFile($source, $staging.'/'.$name);
            $roots[$root] = true;
        }

        return array_keys($roots);
    }

    /**
     * Replaces the live paths with the staged ones: the current path is renamed aside, the
     * staged path moved into place. If anything fails mid-way, the already swapped paths are
     * rolled back. The old paths are only deleted after ALL swaps succeeded - so the previous
     * state survives until the very end.
     *
     * @param list<string> $roots
     */
    private function swapPaths(array $roots): void
    {
        $fs = new Filesystem();
        $swapped = [];

        try {
            foreach ($roots as $relativePath) {
                $staged = $this->store->stagingDir().'/'.$relativePath;

                if (!file_exists($staged)) {
                    continue;
                }

                $target = $this->projectDir.'/'.$relativePath;
                $old = $target.'.restore-old';

                // Leftover from an earlier crashed run - the current live path wins.
                if (file_exists($old) || is_link($old)) {
                    $fs->remove($old);
                }

                $hadTarget = file_exists($target) || is_link($target);

                if ($hadTarget) {
                    $this->rename($target, $old);
                }

                // Register the swap BEFORE moving the staged path into place: should the move
                // below fail, the rollback must still restore the live path that was just
                // moved aside (otherwise the target would simply be gone).
                $swapped[] = [$target, $hadTarget ? $old : null];

                $fs->mkdir(\dirname($target));
                $this->movePath($staged, $target);
            }
        } catch (\Throwable $t) {
            foreach (array_reverse($swapped) as [$target, $old]) {
                try {
                    $fs->remove($target);

                    // $old sits next to $target (same directory), so this rename is always
                    // intra-filesystem and safe.
                    if (null !== $old) {
                        @rename($old, $target);
                    }
                } catch (\Throwable) {
                    // Best effort - the .restore-old copy stays behind for manual recovery.
                }
            }

            throw new RestoreException('Replacing the files failed and was rolled back: '.$t->getMessage(), 0, $t);
        }

        foreach ($swapped as [, $old]) {
            if (null !== $old) {
                $fs->remove($old);
            }
        }
    }

    /**
     * Moves a path into place. Uses an atomic rename when source and target share a
     * filesystem (the normal case). Across filesystems (e.g. a separately mounted var/,
     * where the staging dir and the project dir differ) rename fails with EXDEV and PHP does
     * not fall back - so we copy and delete instead. Not atomic, but swapPaths' rollback
     * covers a mid-way failure, so no data is lost.
     */
    private function movePath(string $from, string $to): void
    {
        if (@rename($from, $to)) {
            return;
        }

        $fs = new Filesystem();

        if (is_dir($from)) {
            $fs->mirror($from, $to);
        } else {
            $fs->copy($from, $to, true);
        }

        $fs->remove($from);
    }

    /**
     * Clears the caches that could serve stale content after a restore. A pure database
     * restore only needs the HTTP cache and the cache pools; once files (templates,
     * contao/ config, translations ...) were replaced, their caches have to go too.
     *
     * The compiled DI container (Container*) is deliberately left alone: its service
     * factories are lazy-loaded single files, and deleting them mid-request crashes the
     * rendering of the very result page (verified). It only becomes stale through
     * config changes, which require a composer/deploy run (with a cache rebuild) anyway.
     */
    private function clearCaches(bool $filesRestored): void
    {
        $fs = new Filesystem();

        $subDirs = $filesRestored
            ? ['contao', 'twig', 'translations', 'pools', 'http_cache']
            : ['pools', 'http_cache'];

        foreach (glob($this->projectDir.'/var/cache/*', GLOB_ONLYDIR) ?: [] as $envDir) {
            foreach ($subDirs as $subDir) {
                $fs->remove($envDir.'/'.$subDir);
            }
        }
    }

    /**
     * Runs the DBAFS synchronization (the programmatic contao:filesync). Never fatal:
     * a failure (e.g. memory limit on huge files/ trees) becomes a warning telling the
     * user to run contao:filesync manually.
     *
     * @param list<array{0: string, 1: list<int|string>}> $steps
     * @param list<array{0: string, 1: list<int|string>}> $warnings
     */
    private function runFilesync(array &$steps, array &$warnings): void
    {
        try {
            $changeSet = $this->dbafsManager->sync();
            $changes = \count($changeSet->getItemsToCreate()) + \count($changeSet->getItemsToUpdate()) + \count($changeSet->getItemsToDelete());
            $steps[] = ['filesync_done', [$changes]];
        } catch (\Throwable $t) {
            $this->log('DBAFS synchronization after the restore failed: '.$t->getMessage(), true);
            $warnings[] = ['filesync_failed', [$t->getMessage()]];
        }
    }

    /**
     * Runs contao:migrate exactly like the CLI would (framework migrations plus schema
     * updates WITHOUT drops), so a backup from an older Contao version is consistent with
     * the installed code right after the restore. --no-backup is essential: the automatic
     * pre-migration backup would trigger the retention policy again. Never fatal - on
     * failure the database is still fully restored and the command can be re-run manually.
     *
     * @param list<array{0: string, 1: list<int|string>}> $steps
     * @param list<array{0: string, 1: list<int|string>}> $warnings
     */
    private function runContaoMigrate(array &$steps, array &$warnings): void
    {
        try {
            $application = new Application($this->kernel);
            $application->setAutoExit(false);
            $application->setCatchExceptions(false);

            $output = new BufferedOutput();
            $exitCode = $application->run(
                new ArrayInput([
                    'command' => 'contao:migrate',
                    '--no-backup' => true,
                    '--no-interaction' => true,
                ]),
                $output,
            );

            if (0 === $exitCode) {
                $steps[] = ['migrate_done', []];
                $this->log('contao:migrate after the restore completed');
            } else {
                $tail = substr(trim($output->fetch()), -400);
                $warnings[] = ['migrate_failed', [\sprintf('exit code %d: %s', $exitCode, $tail)]];
                $this->log('contao:migrate after the restore failed: '.$tail, true);
            }
        } catch (\Throwable $t) {
            $warnings[] = ['migrate_failed', [$t->getMessage()]];
            $this->log('contao:migrate after the restore failed: '.$t->getMessage(), true);
        }
    }

    /**
     * Compares the (restored) composer.lock with vendor/composer/installed.json: how many
     * packages would "composer install" add, remove or change. Pure file reading - no
     * Composer run involved. Null if either file cannot be read.
     *
     * @return array{install: int, remove: int, change: int}|null
     */
    private function computeComposerDiff(): array|null
    {
        try {
            $lock = json_decode((string) file_get_contents($this->projectDir.'/composer.lock'), true);
            $installed = json_decode((string) file_get_contents($this->projectDir.'/vendor/composer/installed.json'), true);

            if (!\is_array($lock) || !\is_array($installed)) {
                return null;
            }

            $lockPackages = [];

            foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
                $lockPackages[(string) $package['name']] = (string) ($package['version'] ?? '');
            }

            $installedPackages = [];

            foreach ($installed['packages'] ?? [] as $package) {
                $installedPackages[(string) $package['name']] = (string) ($package['version'] ?? '');
            }

            $install = \count(array_diff_key($lockPackages, $installedPackages));
            $remove = \count(array_diff_key($installedPackages, $lockPackages));
            $change = 0;

            foreach (array_intersect_key($lockPackages, $installedPackages) as $name => $version) {
                if ($installedPackages[$name] !== $version) {
                    ++$change;
                }
            }

            return ['install' => $install, 'remove' => $remove, 'change' => $change];
        } catch (\Throwable) {
            return null;
        }
    }

    private function assertEnoughDiskSpace(int $requiredBytes): void
    {
        $free = @disk_free_space($this->projectDir);

        if (false === $free) {
            return; // unknown - proceed
        }

        if ($free < $requiredBytes + self::DISK_SPACE_MARGIN) {
            throw new RestoreException(\sprintf('Not enough free disk space: the archive unpacks to about %d MB but only %d MB are free.', (int) round($requiredBytes / 1048576), (int) round($free / 1048576)));
        }
    }

    /**
     * @param resource $source
     */
    private function copyStreamToFile($source, string $targetFile): void
    {
        try {
            (new Filesystem())->mkdir(\dirname($targetFile));

            $target = fopen($targetFile, 'wb');

            if (!\is_resource($target)) {
                throw new RestoreException(\sprintf('Could not write "%s".', $targetFile));
            }

            stream_copy_to_stream($source, $target);
            fclose($target);
        } finally {
            // Always release the source handle, even if mkdir/fopen threw above.
            fclose($source);
        }
    }

    private function rename(string $from, string $to): void
    {
        if (!@rename($from, $to)) {
            throw new RestoreException(\sprintf('Could not move "%s" to "%s".', $from, $to));
        }
    }

    /**
     * Only one restore may run at a time (guarded by a lock file, non-blocking).
     *
     * @return resource
     */
    private function acquireLock()
    {
        (new Filesystem())->mkdir($this->store->directory());

        $handle = fopen($this->store->directory().'/restore.lock', 'c');

        if (!\is_resource($handle) || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (\is_resource($handle)) {
                fclose($handle);
            }

            throw new RestoreException('Another restore is already running.');
        }

        return $handle;
    }

    /**
     * @param resource|null $handle
     */
    private function releaseLock($handle): void
    {
        if (\is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Restores are destructive admin actions - log start, success and failure to the
     * Contao log (System > System log and var/logs) as an audit trail.
     */
    private function log(string $message, bool $error = false): void
    {
        $context = ['contao' => new ContaoContext(self::class, $error ? ContaoContext::ERROR : ContaoContext::GENERAL)];

        if ($error) {
            $this->logger->error('Backup restore: '.$message, $context);
        } else {
            $this->logger->info('Backup restore: '.$message, $context);
        }
    }
}
