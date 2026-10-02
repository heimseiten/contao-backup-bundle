<?php

declare(strict_types=1);

namespace Heimseiten\ContaoBackupBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;
use Heimseiten\ContaoBackupBundle\EventListener\SymlinksWithoutDatabaseListener;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Creates the symlinks that were skipped while the database could not be reached (see
 * SymlinksWithoutDatabaseListener), as soon as the Contao tables exist. In the flow of an
 * installation package that is the step "Check database" after the database import.
 */
final class CompleteSymlinksMigration extends AbstractMigration
{
    public function __construct(
        private readonly Connection $connection,
        private readonly KernelInterface $kernel,
        private readonly string $projectDir,
        private readonly string $webDir,
    ) {
    }

    public function getName(): string
    {
        return 'Backup bundle: create the symlinks of extensions that were skipped during the setup without database';
    }

    public function shouldRun(): bool
    {
        if (!is_file($this->markerPath())) {
            return false;
        }

        // Before the tables exist (empty database) the extensions could not create their
        // symlinks either, so the marker has to stay until the import or the schema update.
        try {
            return $this->connection->createSchemaManager()->tablesExist(['tl_user']);
        } catch (\Throwable) {
            return false;
        }
    }

    public function run(): MigrationResult
    {
        $output = new BufferedOutput();

        $application = new Application($this->kernel);
        $application->setAutoExit(false);

        $status = $application->run(
            new ArrayInput([
                'command' => 'contao:symlinks',
                'target' => Path::makeRelative($this->webDir, $this->projectDir),
            ]),
            $output,
        );

        if (0 !== $status) {
            return $this->createResult(false, 'The symlinks could not be created: '.trim(substr($output->fetch(), -600)));
        }

        (new Filesystem())->remove($this->markerPath());

        return $this->createResult(true, 'The symlinks of the extensions were created.');
    }

    private function markerPath(): string
    {
        return Path::join($this->projectDir, SymlinksWithoutDatabaseListener::MARKER);
    }
}
