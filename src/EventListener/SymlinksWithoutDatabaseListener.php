<?php

declare(strict_types=1);

namespace Heimseiten\ContaoBackupBundle\EventListener;

use Contao\CoreBundle\Event\ContaoCoreEvents;
use Contao\CoreBundle\Event\GenerateSymlinksEvent;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * Keeps "contao-setup" - and with it "composer install" - working while the database cannot be
 * reached yet. That is the normal state of a new installation from an installation package: the
 * Contao Manager asks for the database access only AFTER the installation. Extensions that add
 * symlinks depending on database content do not always cope with a missing connection (the
 * TinyMCE plugin loader queries its table in this very event). Their exception aborts the whole
 * setup, and the Manager never reaches its "Database connection" step.
 *
 * This listener runs before all others. If the database cannot be queried, it stops the event so
 * that the remaining listeners are skipped, and leaves a marker. By then Contao has already
 * created all standard symlinks - the event is dispatched last. CompleteSymlinksMigration creates
 * the skipped ones as soon as the database is there.
 */
#[AsEventListener(event: ContaoCoreEvents::GENERATE_SYMLINKS, priority: 4096)]
final class SymlinksWithoutDatabaseListener
{
    /**
     * Relative to the project directory.
     */
    public const MARKER = 'var/backup_symlinks_pending';

    public function __construct(
        private readonly Connection $connection,
        private readonly string $projectDir,
    ) {
    }

    public function __invoke(GenerateSymlinksEvent $event): void
    {
        try {
            // A connection without a selected database (no name in the URL) answers "SELECT 1"
            // but fails on every table access, so the database name has to be there as well.
            if (null !== $this->connection->fetchOne('SELECT DATABASE()')) {
                return;
            }
        } catch (\Throwable) {
            // The database is not reachable (yet): skip the listeners that need it.
        }

        $event->stopPropagation();

        try {
            (new Filesystem())->dumpFile(Path::join($this->projectDir, self::MARKER), date('c')."\n");
        } catch (\Throwable) {
            // Without the marker the skipped symlinks are not created automatically later on,
            // "composer install" does it as well - no reason to abort the setup.
        }
    }
}
