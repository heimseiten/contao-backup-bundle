<?php

declare(strict_types=1);

namespace Heimseiten\ContaoBackupBundle;

use Contao\CoreBundle\Monolog\ContaoContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Automatic full backups: a cron job that stores a complete archive (database + files) in
 * var/backups on a schedule of its own.
 *
 * Deliberately separate from Contao's automatic DATABASE backups, because the two are
 * nothing alike in size: a dump is a fraction of a megabyte, a full archive can be a
 * gigabyte. Running it daily and keeping five of them - the database defaults - would fill
 * a shared host's disk with copies of files that never changed. Hence its own interval, its
 * own (small) retention, a disk space check that skips rather than fills the disk, and, by
 * default, a run only when something actually changed since the last archive.
 */
final class AutomaticBackup
{
    /**
     * File name prefix of the archives created here. The cleanup only ever touches files
     * with this prefix, so archives stored by hand - or uploaded per FTP - are never
     * deleted by the automatic run.
     */
    public const PREFIX = 'auto-backup';

    public const INTERVALS = ['daily', 'weekly', 'monthly'];

    private const INTERVAL_SECONDS = [
        // A little less than the nominal period, so a cron that fires at a slightly
        // varying time does not skip a whole cycle.
        'daily' => 82800,      // 23 h
        'weekly' => 601200,    // 6 d 23 h
        'monthly' => 2588400,  // 29 d 23 h
    ];

    public function __construct(
        private readonly BackupDownloader $downloader,
        private readonly string $projectDir,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Runs one scheduled check. Returns what happened, so the back end can show it.
     *
     * @param string $scope 'cli' or 'web' - the Contao cron scope
     *
     * @return array{status: string, detail: string}
     */
    public function run(string $scope): array
    {
        $settings = $this->settings();

        if (!$settings['enabled']) {
            return ['status' => 'disabled', 'detail' => ''];
        }

        // A full archive takes minutes. In the web cron that happens inside a request, where
        // PHP-FPM's request_terminate_timeout ends it - so it only runs there on request.
        if ('cli' !== $scope && !$settings['allowWebCron']) {
            return $this->remember('needs_cli', '');
        }

        if (!$this->isDue($settings)) {
            return ['status' => 'not_due', 'detail' => ''];
        }

        $lock = $this->acquireLock();

        if (null === $lock) {
            return ['status' => 'locked', 'detail' => ''];
        }

        try {
            $scan = $this->scan();
            $state = $this->state();

            // Nothing changed since the last archive: skip. Saves a gigabyte of identical
            // data on every run of a site whose files rarely change. "Unchanged" has to
            // hold in BOTH ways: the key must match AND nothing may carry a timestamp from
            // the last run's second or later (see scan()).
            $unchanged = $scan['key'] === ($state['fingerprint'] ?? null)
                && $scan['newest'] < (int) ($state['time'] ?? 0);

            if ($settings['onlyOnChange'] && $unchanged && [] !== $this->ownArchives()) {
                return $this->remember('skipped_unchanged', '', $scan['key']);
            }

            $fingerprint = $scan['key'];
            $result = $this->downloader->storeArchive(true, null, self::PREFIX);
            $deleted = $this->cleanUp($settings['keep']);

            $this->logger->info(
                \sprintf('Automatic full backup created: %s (%d bytes), %d old archive(s) removed', $result['path'], $result['size'], $deleted),
                ['contao' => new ContaoContext(__METHOD__, ContaoContext::CRON)],
            );

            return $this->remember('created', $result['name'], $fingerprint);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Automatic full backup failed: '.$e->getMessage(),
                ['contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR), 'exception' => $e],
            );

            return $this->remember('failed', $e->getMessage());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @deprecated kept for readability of the state file; use scan()
     */
    public function fingerprint(): string
    {
        return $this->scan()['key'];
    }

    /**
     * Stats everything that goes into a backup and condenses it into a comparable key plus
     * the newest timestamp seen. Several signals on purpose, because no single one catches
     * every change:
     *
     *  - file count and total size catch added and deleted files, even when their
     *    timestamps claim to be years old;
     *  - the SUM of all mtimes and ctimes (not the maximum) changes as soon as a single
     *    file changes, even when some other file remains the newest one;
     *  - ctime matters because it cannot be forged: an FTP client that preserves the
     *    original timestamp of an upload leaves a file that looks years old by mtime,
     *    while its inode was written just now;
     *  - the newest timestamp is reported separately, so the caller can additionally ask
     *    "did anything happen at or after the last run?" - that catches the one case the
     *    key alone cannot see, namely a change within the same second as the last run
     *    (timestamps only have second resolution). In doubt this errs towards one backup
     *    too many rather than one too few.
     *
     * Only stats are read, never file contents, so this stays fast even on large sites.
     *
     * @return array{key: string, newest: int, files: int, bytes: int}
     */
    public function scan(): array
    {
        $count = 0;
        $bytes = 0;
        $sumMtime = 0;
        $sumCtime = 0;
        $newest = 0;

        foreach (BackupDownloader::PATHS as $relativePath) {
            $absolutePath = $this->projectDir.'/'.$relativePath;

            if (is_file($absolutePath)) {
                ++$count;
                $bytes += (int) filesize($absolutePath);
                $mtime = (int) filemtime($absolutePath);
                $ctime = (int) filectime($absolutePath);
                $sumMtime += $mtime;
                $sumCtime += $ctime;
                $newest = max($newest, $mtime, $ctime);

                continue;
            }

            if (!is_dir($absolutePath)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absolutePath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($iterator as $item) {
                if ($item->isLink()) {
                    continue;
                }

                // Directories count too: adding or removing a file updates the mtime of its
                // directory even when the file itself carries an old timestamp.
                $mtime = (int) $item->getMTime();
                $ctime = (int) $item->getCTime();
                $sumMtime += $mtime;
                $sumCtime += $ctime;
                $newest = max($newest, $mtime, $ctime);

                if ($item->isFile() && $item->isReadable()) {
                    ++$count;
                    $bytes += (int) $item->getSize();
                }
            }
        }

        return [
            'key' => \sprintf('%d:%d:%d:%d', $count, $bytes, $sumMtime, $sumCtime),
            'newest' => $newest,
            'files' => $count,
            'bytes' => $bytes,
        ];
    }

    /**
     * @return array{enabled: bool, interval: string, keep: int, onlyOnChange: bool, allowWebCron: bool}
     */
    public function settings(): array
    {
        $data = [];

        if (is_file($this->settingsFile())) {
            $decoded = json_decode((string) file_get_contents($this->settingsFile()), true);
            $data = \is_array($decoded) ? $decoded : [];
        }

        $interval = (string) ($data['interval'] ?? 'weekly');

        return [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'interval' => \in_array($interval, self::INTERVALS, true) ? $interval : 'weekly',
            'keep' => max(1, (int) ($data['keep'] ?? 2)),
            'onlyOnChange' => (bool) ($data['onlyOnChange'] ?? true),
            'allowWebCron' => (bool) ($data['allowWebCron'] ?? false),
        ];
    }

    public function saveSettings(bool $enabled, string $interval, int $keep, bool $onlyOnChange, bool $allowWebCron): void
    {
        if (!\in_array($interval, self::INTERVALS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown interval "%s".', $interval));
        }

        (new Filesystem())->mkdir(\dirname($this->settingsFile()));

        file_put_contents($this->settingsFile(), json_encode([
            'enabled' => $enabled,
            'interval' => $interval,
            'keep' => max(1, $keep),
            'onlyOnChange' => $onlyOnChange,
            'allowWebCron' => $allowWebCron,
        ], JSON_PRETTY_PRINT));
    }

    /**
     * What the last run did - for the status line in the back end.
     *
     * @return array{status: string, detail: string, time: int, fingerprint: string|null}|array{}
     */
    public function state(): array
    {
        if (!is_file($this->stateFile())) {
            return [];
        }

        $data = json_decode((string) file_get_contents($this->stateFile()), true);

        return \is_array($data) ? $data : [];
    }

    /**
     * The archives this automatic backup created, newest first.
     *
     * @return list<array{name: string, path: string, size: int, modified: int}>
     */
    public function ownArchives(): array
    {
        return array_values(array_filter(
            $this->downloader->storedArchives(),
            static fn (array $a): bool => str_starts_with($a['name'], self::PREFIX.'_'),
        ));
    }

    private function isDue(array $settings): bool
    {
        $state = $this->state();
        $last = (int) ($state['time'] ?? 0);

        // Never ran, or the archive it created is gone: run now.
        if (0 === $last || [] === $this->ownArchives()) {
            return true;
        }

        return time() - $last >= self::INTERVAL_SECONDS[$settings['interval']];
    }

    /**
     * Deletes the oldest of OUR archives beyond the configured number. Archives without the
     * prefix (stored by hand, uploaded per FTP) are never considered.
     */
    private function cleanUp(int $keep): int
    {
        $deleted = 0;

        foreach (\array_slice($this->ownArchives(), max(1, $keep)) as $archive) {
            if (@unlink($this->projectDir.'/'.$archive['path'])) {
                ++$deleted;
            }
        }

        return $deleted;
    }

    /**
     * @return array{status: string, detail: string}
     */
    private function remember(string $status, string $detail, string|null $fingerprint = null): array
    {
        $state = $this->state();

        file_put_contents($this->stateFile(), json_encode([
            'status' => $status,
            'detail' => $detail,
            'time' => time(),
            'fingerprint' => $fingerprint ?? ($state['fingerprint'] ?? null),
        ], JSON_PRETTY_PRINT));

        return ['status' => $status, 'detail' => $detail];
    }

    /**
     * @return resource|null
     */
    private function acquireLock()
    {
        (new Filesystem())->mkdir($this->projectDir.'/var');

        $handle = fopen($this->projectDir.'/var/backup_auto.lock', 'c');

        if (!\is_resource($handle) || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (\is_resource($handle)) {
                fclose($handle);
            }

            return null;
        }

        return $handle;
    }

    private function settingsFile(): string
    {
        return $this->projectDir.'/var/backup_auto.json';
    }

    private function stateFile(): string
    {
        return $this->projectDir.'/var/backup_auto_state.json';
    }
}
