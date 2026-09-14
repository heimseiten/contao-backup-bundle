<?php

declare(strict_types=1);

namespace Heimseiten\ContaoBackupBundle;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;

/**
 * Hands the daily cron tick to the automatic full backup, which decides for itself whether
 * this run is actually due (its own interval can be weekly or monthly) and whether anything
 * changed at all. Registered as "daily" because that is the finest granularity a full
 * backup ever needs.
 */
#[AsCronJob('daily')]
final class AutomaticBackupCron
{
    public function __construct(private readonly AutomaticBackup $automaticBackup)
    {
    }

    public function __invoke(string $scope): void
    {
        $this->automaticBackup->run($scope);
    }
}
