<?php

declare(strict_types=1);

namespace Heimseiten\ContaoBackupBundle;

use Contao\CoreBundle\Doctrine\Backup\Backup;
use Contao\CoreBundle\Doctrine\Backup\RetentionPolicy;
use Contao\CoreBundle\Doctrine\Backup\RetentionPolicyInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Decorates Contao's retention policy so the values (how many/which automatic database
 * backups are kept, see contao.backup.keep_max/keep_intervals) can be configured in the
 * back end. Overrides live in var/backup_settings.json - no container rebuild needed and
 * readable from both web and CLI. Without the file, the original policy applies.
 */
final class ConfigurableRetentionPolicy implements RetentionPolicyInterface
{
    /**
     * The documented Contao defaults, shown as placeholders in the back end.
     */
    public const DEFAULT_KEEP_MAX = 5;

    public const DEFAULT_KEEP_INTERVALS = '1D,7D,14D,1M';

    public function __construct(
        private readonly RetentionPolicyInterface $inner,
        private readonly string $projectDir,
    ) {
    }

    public function apply(Backup $currentBackup, array $allBackups): array
    {
        $settings = $this->settings();

        if (null === $settings) {
            return $this->inner->apply($currentBackup, $allBackups);
        }

        // 0 = keep everything, no intervals: nothing may be deleted at all.
        if (0 === $settings['keepMax'] && [] === $settings['keepIntervals']) {
            return $allBackups;
        }

        return (new RetentionPolicy($settings['keepMax'], $settings['keepIntervals']))->apply($currentBackup, $allBackups);
    }

    /**
     * The configured override or null if the Contao configuration applies.
     *
     * @return array{keepMax: int, keepIntervals: list<string>}|null
     */
    public function settings(): array|null
    {
        $file = $this->settingsFile();

        if (!is_file($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);

        if (!\is_array($data) || !\is_int($data['keepMax'] ?? null) || !\is_array($data['keepIntervals'] ?? null)) {
            return null;
        }

        return ['keepMax' => max(0, $data['keepMax']), 'keepIntervals' => array_values($data['keepIntervals'])];
    }

    /**
     * Validates (by probing Contao's own policy, which throws on bad intervals) and stores
     * the override.
     *
     * @param list<string> $keepIntervals interval strings without the "P" prefix, e.g. ["1D", "1M"]
     *
     * @throws \Exception if an interval cannot be parsed
     */
    public function saveSettings(int $keepMax, array $keepIntervals): void
    {
        new RetentionPolicy(max(0, $keepMax), $keepIntervals);

        (new Filesystem())->mkdir(\dirname($this->settingsFile()));

        file_put_contents(
            $this->settingsFile(),
            json_encode(['keepMax' => max(0, $keepMax), 'keepIntervals' => array_values($keepIntervals)], JSON_PRETTY_PRINT),
        );
    }

    /**
     * Removes the override - Contao's configuration applies again.
     */
    public function clearSettings(): void
    {
        (new Filesystem())->remove($this->settingsFile());
    }

    private function settingsFile(): string
    {
        return $this->projectDir.'/var/backup_settings.json';
    }
}
