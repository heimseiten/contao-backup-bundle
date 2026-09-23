<?php

declare(strict_types=1);

namespace Heimseiten\ContaoBackupBundle;

use Contao\BackendModule;
use Contao\BackendUser;
use Contao\Config;
use Contao\CoreBundle\Exception\AccessDeniedException;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Exception\ResponseException;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\Date;
use Contao\Environment;
use Contao\Input;
use Contao\StringUtil;
use Contao\System;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Backend module "Sicherung": download a full backup of the database and/or the
 * relevant files/folders, and restore a backup (from var/backups, an uploaded archive
 * or an archive already lying on the server). Administrators only.
 */
class BackupModule extends BackendModule
{
    /**
     * The confirmation words accepted for a restore (any language, case-insensitive).
     */
    private const CONFIRM_WORDS = ['WIEDERHERSTELLEN', 'RESTORE'];

    protected $strTemplate = 'be_backup';

    private string|null $restoreError = null;

    private string|null $restoreErrorSafetyBackup = null;

    private RestoreResult|null $restoreResult = null;

    private string|null $settingsMessage = null;

    private string|null $settingsError = null;

    private string|null $storeError = null;

    private string|null $autoMessage = null;

    private string|null $autoError = null;

    protected function compile(): void
    {
        if (!BackendUser::getInstance()->isAdmin) {
            throw new AccessDeniedException('Only administrators may download or restore backups.');
        }

        $container = System::getContainer();
        $downloader = $container->get(BackupDownloader::class);
        $store = $container->get(RestoreArchiveStore::class);

        // The download forms post the request token, which Contao validates for us.
        $token = (string) Input::post('download_token');
        $formSubmit = (string) Input::post('FORM_SUBMIT');

        $store->collectGarbage();

        // Tiny streamed probe so the front end can detect a compressing proxy (which strips the
        // size headers) before any real download and show a hint.
        if ('tl_backup_probe' === $formSubmit) {
            throw new ResponseException($downloader->createProbeResponse());
        }

        if (\in_array($formSubmit, ['tl_backup_full', 'tl_backup_database', 'tl_backup_files', 'tl_backup_package'], true)) {
            try {
                $response = match ($formSubmit) {
                    'tl_backup_full' => $downloader->createFullResponse(),
                    'tl_backup_database' => $downloader->createDatabaseResponse(),
                    'tl_backup_files' => $downloader->createFilesResponse(),
                    'tl_backup_package' => $downloader->createInstallPackageResponse($this->packageDescription()),
                };
            } catch (\Throwable $e) {
                // Make the reason for a failed backup easy to find (e.g. var/backups not
                // writable): log it to the Contao log and re-throw so Contao shows the error.
                $this->logBackupError($formSubmit, $e);

                throw $e;
            }

            throw new ResponseException($this->withDownloadSignal($response, $token));
        }

        if (\in_array($formSubmit, ['tl_backup_store_full', 'tl_backup_store_files', 'tl_backup_store_database', 'tl_backup_store_package'], true)) {
            $this->handleStoreOnServer($downloader, $formSubmit);
        }

        match ($formSubmit) {
            'tl_backup_upload_chunk' => throw new ResponseException($this->handleChunkUpload($store)),
            'tl_backup_upload' => $this->handleDirectUpload($store),
            'tl_backup_select_file' => $this->handleServerArchiveSelection($store),
            'tl_backup_delete_file' => $this->handleServerArchiveDeletion($store),
            'tl_backup_discard' => $this->handleDiscard($store),
            'tl_backup_restore_server' => $this->handleServerRestore(),
            'tl_backup_restore_archive' => $this->handleArchiveRestore($store),
            'tl_backup_settings' => $this->handleRetentionSettings(),
            'tl_backup_auto_settings' => $this->handleAutomaticSettings(),
            'tl_backup_auto_now' => $this->handleAutomaticRunNow(),
            default => null,
        };

        $this->prepareTemplate($store, $downloader);
    }

    /**
     * Keeps a backup on the server (var/backups) instead of downloading it.
     *
     * Building a multi-GB archive takes minutes, and a silent request that long runs into
     * the web server's read timeout. With JavaScript the answer is therefore a stream of
     * progress lines (NDJSON) - it keeps the connection busy AND feeds the progress bar.
     * Without JavaScript the request simply runs to the end and redirects.
     */
    private function handleStoreOnServer(BackupDownloader $downloader, string $formSubmit): void
    {
        $this->liftTimeLimit();

        $description = $this->packageDescription();

        $store = static fn (callable|null $onProgress) => match ($formSubmit) {
            'tl_backup_store_full' => $downloader->storeArchive(true, $onProgress),
            'tl_backup_store_files' => $downloader->storeArchive(false, $onProgress),
            'tl_backup_store_database' => $downloader->storeDatabaseBackup(),
            'tl_backup_store_package' => $downloader->storeInstallPackage($onProgress, $description),
        };

        if ('1' !== (string) Input::post('stream')) {
            // No JavaScript: store, then POST/redirect/GET with the result in the query.
            try {
                $result = $store(null);
            } catch (\Throwable $e) {
                $this->storeError = \sprintf($this->loadLanguage()['storeFailed'], StringUtil::specialchars($e->getMessage()));

                return;
            }

            throw new RedirectResponseException($this->requestUriWith('stored', $result['name']));
        }

        $response = new StreamedResponse(function () use ($store): void {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            $send = static function (array $payload): void {
                echo json_encode($payload)."\n";
                flush();
            };

            // First line right away, so proxies start passing the response through.
            $send(['progress' => 0, 'total' => null]);

            try {
                $result = $store(static fn (int $written, int|null $total) => $send(['progress' => $written, 'total' => $total]));
                $send(['done' => true] + $result);
            } catch (\Throwable $e) {
                $send(['error' => $e->getMessage()]);
            }
        });

        $response->headers->set('Content-Type', 'application/x-ndjson');
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Accel-Buffering', 'no');

        throw new ResponseException($response);
    }

    /**
     * The current back-end URL with one extra query parameter (used for the redirect after
     * a store without JavaScript).
     */
    private function requestUriWith(string $key, string $value): string
    {
        $uri = (string) Environment::get('requestUri');
        $uri = (string) preg_replace('/[?&]'.preg_quote($key, '/').'=[^&]*/', '', $uri);

        return $uri.(str_contains($uri, '?') ? '&' : '?').$key.'='.rawurlencode($value);
    }

    /**
     * Receives one chunk of the JavaScript-driven archive upload and, with the final
     * chunk, turns the parts into the uploaded archive. Responds with JSON.
     */
    private function handleChunkUpload(RestoreArchiveStore $store): Response
    {
        $this->liftTimeLimit();

        $request = System::getContainer()->get('request_stack')->getCurrentRequest();

        try {
            $chunk = $request?->files->get('chunk');

            // An invalid/missing file usually means the chunk exceeded a server limit.
            if (!$chunk instanceof UploadedFile || !$chunk->isValid()) {
                return new JsonResponse(['error' => 'The chunk did not arrive (server upload limits?). Please retry.'], 400);
            }

            $state = $store->appendChunk(
                (string) $request->request->get('upload_id'),
                (int) $request->request->get('offset'),
                $chunk->getPathname(),
            );

            if (1 === (int) $request->request->get('last')) {
                $store->finalizeChunked(
                    (string) $request->request->get('upload_id'),
                    (int) $request->request->get('total'),
                    (string) $request->request->get('name'),
                );
            }

            return new JsonResponse(['ok' => true, 'size' => $state['size']]);
        } catch (RestoreException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 409);
        }
    }

    /**
     * Classic (no JavaScript) archive upload: one POST with the whole file, subject to
     * the server's upload limits. Redirects afterwards (POST/redirect/GET).
     */
    private function handleDirectUpload(RestoreArchiveStore $store): void
    {
        $this->liftTimeLimit();

        $lang = $this->loadLanguage();
        $request = System::getContainer()->get('request_stack')->getCurrentRequest();
        $file = $request?->files->get('archive');

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->restoreError = \sprintf(
                $lang['uploadFailed'],
                \sprintf('upload_max_filesize=%s, post_max_size=%s', (string) \ini_get('upload_max_filesize'), (string) \ini_get('post_max_size')),
            );

            return;
        }

        if (!preg_match('/\.zip$/i', $file->getClientOriginalName())) {
            $this->restoreError = $lang['uploadNotZip'];

            return;
        }

        try {
            $store->storeUploadedFile($file);
        } catch (\Throwable $e) {
            $this->restoreError = \sprintf($lang['uploadFailed'], $e->getMessage());

            return;
        }

        throw new RedirectResponseException(Environment::get('requestUri'));
    }

    /**
     * Stages an archive that is already on the server (put there per FTP/SSH or by the
     * hosting file manager) - no upload through the browser at all. The file itself is
     * left where it is; only its path is remembered.
     */
    private function handleServerArchiveSelection(RestoreArchiveStore $store): void
    {
        $lang = $this->loadLanguage();

        try {
            $store->selectServerArchive((string) Input::post('server_archive'));
        } catch (\Throwable $e) {
            $this->restoreError = \sprintf($lang['serverFileFailed'], StringUtil::specialchars($e->getMessage()));

            return;
        }

        throw new RedirectResponseException(Environment::get('requestUri'));
    }

    /**
     * Deletes one of the archives lying on the server, so a large file need not be
     * removed per FTP after the restore.
     */
    private function handleServerArchiveDeletion(RestoreArchiveStore $store): void
    {
        $lang = $this->loadLanguage();

        try {
            $name = $store->deleteServerArchive((string) Input::post('server_archive'));
        } catch (\Throwable $e) {
            $this->restoreError = \sprintf($lang['serverFileFailed'], StringUtil::specialchars($e->getMessage()));

            return;
        }

        $this->log(\sprintf('Backup archive "%s" deleted from the server', $name));

        throw new RedirectResponseException(Environment::get('requestUri'));
    }

    private function handleDiscard(RestoreArchiveStore $store): void
    {
        $store->discard();

        throw new RedirectResponseException(Environment::get('requestUri'));
    }

    /**
     * Restores a database backup from var/backups (drop all tables, import the dump).
     */
    private function handleServerRestore(): void
    {
        $this->liftTimeLimit();

        $lang = $this->loadLanguage();
        $backupName = (string) Input::post('backup_name');

        if ('' === $backupName) {
            $this->restoreError = $lang['noBackupSelected'];

            return;
        }

        if (!$this->confirmWordValid()) {
            $this->restoreError = \sprintf($lang['confirmWordWrong'], $lang['confirmWord']);

            return;
        }

        $options = new RestoreOptions(
            restoreDatabase: true,
            restoreFiles: false,
            includeComposer: false,
            safetyBackup: (bool) Input::post('opt_safety'),
            runFilesync: (bool) Input::post('opt_filesync'),
            runMigrations: (bool) Input::post('opt_migrate'),
        );

        $this->runRestore(fn (BackupRestorer $restorer) => $restorer->restoreFromServerBackup($backupName, $options));
    }

    /**
     * Restores the uploaded archive with the selected parts.
     */
    private function handleArchiveRestore(RestoreArchiveStore $store): void
    {
        $this->liftTimeLimit();

        $lang = $this->loadLanguage();

        if (!$store->hasArchive()) {
            $this->restoreError = $lang['noArchive'];

            return;
        }

        if (!$this->confirmWordValid()) {
            $this->restoreError = \sprintf($lang['confirmWordWrong'], $lang['confirmWord']);

            return;
        }

        $options = new RestoreOptions(
            restoreDatabase: (bool) Input::post('opt_database'),
            restoreFiles: (bool) Input::post('opt_files'),
            includeComposer: (bool) Input::post('opt_composer'),
            safetyBackup: (bool) Input::post('opt_safety'),
            runFilesync: (bool) Input::post('opt_filesync'),
            runMigrations: (bool) Input::post('opt_migrate'),
            allowDowngrade: (bool) Input::post('opt_downgrade'),
        );

        if (!$options->restoreDatabase && !$options->restoreFiles) {
            $this->restoreError = $lang['nothingSelected'];

            return;
        }

        // Friendly, translated version of the restorer's downgrade guard (which stays in
        // place as the second line of defense).
        if ($options->restoreDatabase && !$options->allowDowngrade) {
            try {
                $restorer = System::getContainer()->get(BackupRestorer::class);
                $info = $store->analyze();

                if ($restorer->isDowngrade($info->sourceContaoVersion())) {
                    $this->restoreError = \sprintf($lang['downgradeBlocked'], StringUtil::specialchars((string) $info->sourceContaoVersion()), StringUtil::specialchars((string) $restorer->installedContaoVersion()));

                    return;
                }
            } catch (RestoreException) {
                // The archive problem will surface again in the restore itself.
            }
        }

        $this->runRestore(fn (BackupRestorer $restorer) => $restorer->restoreFromUploadedArchive($options));
    }

    /**
     * @param callable(BackupRestorer): RestoreResult $restore
     */
    private function runRestore(callable $restore): void
    {
        $lang = $this->loadLanguage();

        try {
            $this->restoreResult = $restore(System::getContainer()->get(BackupRestorer::class));
        } catch (RestoreException $e) {
            // The message can embed archive entry names/paths - escape before it is
            // interpolated into the HTML-carrying language string and rendered raw.
            $this->restoreError = \sprintf($lang['restoreFailed'], StringUtil::specialchars($e->getMessage()));
            $this->restoreErrorSafetyBackup = $e->safetyBackupName;
        }
    }

    /**
     * Saves (or resets) how many/which automatic database backups are kept.
     */
    private function handleRetentionSettings(): void
    {
        $lang = $this->loadLanguage();
        $policy = System::getContainer()->get(ConfigurableRetentionPolicy::class);

        if (null !== Input::post('settings_reset')) {
            $policy->clearSettings();
            $this->settingsMessage = $lang['settingsResetDone'];

            return;
        }

        $keepMax = max(0, (int) Input::post('keep_max'));
        $intervals = array_values(array_filter(array_map('trim', explode(',', (string) Input::post('keep_intervals')))));

        try {
            $policy->saveSettings($keepMax, $intervals);
            $this->settingsMessage = $lang['settingsSaved'];
        } catch (\Throwable $e) {
            $this->settingsError = \sprintf($lang['settingsInvalid'], StringUtil::specialchars($e->getMessage()));
        }
    }

    /**
     * Saves the settings of the automatic full backup.
     */
    private function handleAutomaticSettings(): void
    {
        $lang = $this->loadLanguage();
        $automatic = System::getContainer()->get(AutomaticBackup::class);

        try {
            $automatic->saveSettings(
                null !== Input::post('auto_enabled'),
                (string) Input::post('auto_interval'),
                (int) Input::post('auto_keep'),
                null !== Input::post('auto_only_on_change'),
                null !== Input::post('auto_web_cron'),
            );
            $this->autoMessage = $lang['autoSaved'];
        } catch (\Throwable $e) {
            $this->autoError = \sprintf($lang['autoInvalid'], StringUtil::specialchars($e->getMessage()));
        }
    }

    /**
     * Runs the automatic backup once by hand - so the setup can be verified without waiting
     * for the cron. Uses the CLI scope, because the user explicitly asked for it.
     */
    private function handleAutomaticRunNow(): void
    {
        $this->liftTimeLimit();

        $lang = $this->loadLanguage();
        $result = System::getContainer()->get(AutomaticBackup::class)->run('cli');

        if ('failed' === $result['status']) {
            $this->autoError = \sprintf($lang['autoInvalid'], StringUtil::specialchars($result['detail']));

            return;
        }

        $status = $lang['autoStatus_'.$result['status']] ?? $result['status'];
        $detail = '' !== $result['detail'] ? ' <code>'.StringUtil::specialchars($result['detail']).'</code>' : '';

        $this->autoMessage = \sprintf($lang['autoRunDone'], $status.$detail);
    }

    /**
     * The typed confirmation word is checked SERVER-side; both languages are accepted.
     */
    private function confirmWordValid(): bool
    {
        return \in_array(strtoupper(trim((string) Input::post('confirm_word'))), self::CONFIRM_WORDS, true);
    }

    private function prepareTemplate(RestoreArchiveStore $store, BackupDownloader $downloader): void
    {
        $lang = $this->loadLanguage();

        $this->Template->headline = $lang['headline'];
        $this->Template->intro = $lang['intro'];
        $this->Template->securityNote = $lang['securityNote'];
        $this->Template->gzipHint = $lang['gzipHint'];
        $this->Template->fullLabel = $lang['downloadFull'];
        $this->Template->databaseLabel = $lang['downloadDatabase'];
        $this->Template->filesLabel = $lang['downloadFiles'];
        $this->Template->packageLabel = $lang['downloadPackage'];

        // Package names from composer.lock, rendered raw by the .html5 template.
        $this->Template->localPackages = array_map(
            static fn (string $name): string => StringUtil::specialchars($name),
            $downloader->localPackages(),
        );
        $this->Template->databaseItem = $lang['databaseItem'];
        $this->Template->databaseGroup = $lang['databaseGroup'];
        $this->Template->filesGroup = $lang['filesGroup'];
        $this->Template->busyLabel = $lang['busy'];
        $this->Template->startedLabel = $lang['started'];
        $this->Template->doneLabel = $lang['done'];
        $this->Template->errorLabel = $lang['error'];
        $this->Template->filePaths = BackupDownloader::PATHS;
        $this->Template->storeDir = BackupDownloader::STORE_DIR;

        // Units and separators from Contao's translations, so the JavaScript formats sizes
        // exactly like System::getReadableSize() does on the server side.
        $translator = System::getContainer()->get('translator');
        $this->Template->sizeUnits = array_map(
            static fn (int $i): string => $translator->trans('UNITS.'.$i, [], 'contao_default'),
            range(0, 4),
        );
        $this->Template->decimalSeparator = $translator->trans('MSC.decimalSeparator', [], 'contao_default');
        $this->Template->storeError = $this->storeError;
        $this->Template->storedNotice = $this->storedNotice($downloader);
        $this->Template->storedSummary = $this->storedSummary($downloader);
        $this->Template->action = StringUtil::ampersand(Environment::get('requestUri'));
        $this->Template->requestToken = System::getContainer()->get('contao.csrf.token_manager')->getDefaultTokenValue();

        // Restore section
        $restorer = System::getContainer()->get(BackupRestorer::class);

        $this->Template->lang = $lang;
        $this->Template->serverBackups = $this->listServerBackups();
        $this->Template->serverArchives = $this->listServerArchives($store);
        $this->Template->serverArchiveDirs = $store->serverArchiveDirectories();
        $this->Template->archiveFromServer = $store->isServerArchiveSelected();
        $this->Template->archiveServerPath = StringUtil::specialchars((string) $store->selectedArchiveRelativePath());
        $this->Template->maxChunkSize = $this->maxUploadChunkBytes();
        $this->Template->restoreResultSteps = null !== $this->restoreResult ? $this->translateCodes($this->restoreResult->steps) : [];
        $this->Template->restoreResultWarnings = null !== $this->restoreResult ? $this->translateCodes($this->restoreResult->warnings) : [];
        $this->Template->restoreError = $this->restoreError;
        $this->Template->restoreErrorSafetyBackup = $this->restoreErrorSafetyBackup;
        $this->Template->composerDiff = $this->restoreResult?->composerDiff;
        $this->Template->managerUrl = is_file($this->projectDir().'/public/contao-manager.phar.php') ? '/contao-manager.phar.php' : null;
        $this->Template->targetContaoVersion = $restorer->installedContaoVersion();

        // Retention settings for the automatic database backups
        $retention = System::getContainer()->get(ConfigurableRetentionPolicy::class)->settings();
        $this->Template->retention = $retention;
        $this->Template->retentionKeepMax = null !== $retention ? (string) $retention['keepMax'] : '';
        $this->Template->retentionIntervals = null !== $retention ? implode(',', $retention['keepIntervals']) : '';
        $this->Template->retentionDefaultMax = (string) ConfigurableRetentionPolicy::DEFAULT_KEEP_MAX;
        $this->Template->retentionDefaultIntervals = ConfigurableRetentionPolicy::DEFAULT_KEEP_INTERVALS;
        $this->Template->settingsMessage = $this->settingsMessage;
        $this->Template->settingsError = $this->settingsError;

        // Automatic full backups
        $automatic = System::getContainer()->get(AutomaticBackup::class);
        $state = $automatic->state();
        $this->Template->auto = $automatic->settings();
        $this->Template->autoIntervals = AutomaticBackup::INTERVALS;
        $this->Template->autoMessage = $this->autoMessage;
        $this->Template->autoError = $this->autoError;
        $this->Template->autoArchives = array_map(
            fn (array $a): array => [
                'name' => StringUtil::specialchars($a['name']),
                'size' => $this->formatBytes($a['size']),
                'date' => Date::parse(Config::get('datimFormat'), $a['modified']),
            ],
            $automatic->ownArchives(),
        );
        $this->Template->autoState = [] === $state ? null : [
            'status' => (string) ($state['status'] ?? ''),
            'detail' => StringUtil::specialchars((string) ($state['detail'] ?? '')),
            'date' => Date::parse(Config::get('datimFormat'), (int) ($state['time'] ?? 0)),
        ];

        $archive = null;
        $archiveError = null;

        if ($store->hasArchive()) {
            try {
                $info = $store->analyze();

                // Values derived from the uploaded archive (its file names and the
                // backup-manifest.json) are attacker-controlled and rendered raw by the
                // .html5 template - escape everything that reaches the template as text.
                // The compat/phpMismatch LOGIC uses $info directly (numeric-prefix compare),
                // so escaping the display copies here does not affect it.
                $esc = static fn (string|null $v): string|null => null === $v ? null : StringUtil::specialchars($v);

                $archive = [
                    'name' => $esc($info->displayName),
                    'size' => $this->formatBytes($info->archiveSize),
                    'hasDatabase' => $info->hasDatabase(),
                    'databaseName' => null !== $info->databaseEntry ? $esc(basename($info->databaseEntry)) : null,
                    'databaseDate' => null !== $info->databaseCreatedAt ? Date::parse(Config::get('datimFormat'), $info->databaseCreatedAt->getTimestamp()) : null,
                    'hasFiles' => $info->hasFiles(),
                    'paths' => $info->paths,
                    'fileCount' => $info->fileCount,
                    'uncompressed' => $this->formatBytes($info->uncompressedBytes),
                    'hasComposer' => [] !== $info->composerPaths(),
                    'ignored' => $info->ignoredCount + $info->symlinkCount,
                    'sourceContaoVersion' => $esc($info->sourceContaoVersion()),
                    'sourcePhpVersion' => $esc($info->sourcePhpVersion()),
                    'compat' => $this->compatState($restorer, $info),
                    'phpMismatch' => $this->phpMismatch($info),
                ];
            } catch (RestoreException $e) {
                $archiveError = \sprintf($lang['archiveInvalid'], StringUtil::specialchars($e->getMessage()));
            }
        }

        $this->Template->archive = $archive;
        $this->Template->archiveError = $archiveError;
    }

    /**
     * Compatibility between the backup and this installation: 'same' (green), 'older'
     * (backup is older - migrations will bridge the gap), 'newer' (backup is newer -
     * blocked without explicit override) or 'unknown' (archive without a manifest).
     */
    private function compatState(BackupRestorer $restorer, RestoreArchiveInfo $info): string
    {
        $source = $info->sourceContaoVersion();
        $target = $restorer->installedContaoVersion();

        if (null === $source || null === $target) {
            return 'unknown';
        }

        if ($restorer->isDowngrade($source)) {
            return 'newer';
        }

        $short = static fn (string $version): string => preg_match('/^(\d+\.\d+)/', $version, $m) ? $m[1] : $version;

        return $short($source) === $short($target) ? 'same' : 'older';
    }

    /**
     * True if the backup was created on a newer PHP feature version than this server
     * runs - relevant when composer.json/lock are restored (the lock may not be
     * installable on the older PHP).
     */
    private function phpMismatch(RestoreArchiveInfo $info): bool
    {
        $source = $info->sourcePhpVersion();

        if (null === $source || !preg_match('/^(\d+)\.(\d+)/', $source, $m)) {
            return false;
        }

        return version_compare($m[1].'.'.$m[2], \PHP_MAJOR_VERSION.'.'.\PHP_MINOR_VERSION, '>');
    }

    private function projectDir(): string
    {
        return (string) System::getContainer()->getParameter('kernel.project_dir');
    }

    /**
     * The description an installation package carries in its composer.json - where it came
     * from and when, in the language of the back end.
     */
    private function packageDescription(): string
    {
        return \sprintf(
            $this->loadLanguage()['packageDescription'],
            (string) Environment::get('host'),
            Date::parse(Config::get('datimFormat')),
        );
    }

    /**
     * @return list<array{name: string, date: string, size: string}>
     */
    private function listServerBackups(): array
    {
        $backups = [];

        foreach (System::getContainer()->get(BackupRestorer::class)->listServerBackups() as $backup) {
            $backups[] = [
                'name' => $backup->getFilename(),
                'date' => Date::parse(Config::get('datimFormat'), $backup->getCreatedAt()->getTimestamp()),
                'size' => $this->formatBytes($backup->getSize()),
            ];
        }

        return $backups;
    }

    /**
     * Confirmation for an archive that was just stored ("?stored=<name>"). The name is
     * matched against the archives actually present, so nothing from the query string is
     * ever echoed back unchecked.
     *
     * @return array{name: string, path: string, size: string}|null
     */
    private function storedNotice(BackupDownloader $downloader): array|null
    {
        $name = (string) Input::get('stored');

        if ('' === $name) {
            return null;
        }

        foreach ($downloader->storedArchives() as $archive) {
            if ($archive['name'] === $name) {
                return [
                    'name' => StringUtil::specialchars($archive['name']),
                    'path' => StringUtil::specialchars($archive['path']),
                    'size' => $this->formatBytes($archive['size']),
                ];
            }
        }

        // A stored database backup is not a ZIP, so it is not in the list above - confirm it
        // by looking it up on disk. basename() keeps the query string from reaching outside
        // the directory, and a name that is not there simply yields no confirmation (rather
        // than a success message for a file that does not exist).
        $file = $this->projectDir().'/'.BackupDownloader::STORE_DIR.'/'.basename($name);

        if (!is_file($file)) {
            return null;
        }

        return [
            'name' => StringUtil::specialchars(basename($name)),
            'path' => StringUtil::specialchars(BackupDownloader::STORE_DIR.'/'.basename($name)),
            'size' => $this->formatBytes((int) filesize($file)),
        ];
    }

    /**
     * How much space the stored archives occupy - so they cannot pile up unnoticed on the
     * server. Null when there are none.
     *
     * @return array{count: int, size: string}|null
     */
    private function storedSummary(BackupDownloader $downloader): array|null
    {
        $archives = $downloader->storedArchives();

        if ([] === $archives) {
            return null;
        }

        return [
            'count' => \count($archives),
            'size' => $this->formatBytes(array_sum(array_column($archives, 'size'))),
        ];
    }

    /**
     * The backup archives lying in the scanned directories, ready for the template.
     * Names and paths come from the file system, so they are escaped here - the .html5
     * template renders them raw.
     *
     * @return list<array{key: string, path: string, name: string, size: string, date: string}>
     */
    private function listServerArchives(RestoreArchiveStore $store): array
    {
        $archives = [];

        foreach ($store->listServerArchives() as $archive) {
            $archives[] = [
                'key' => $archive['key'],
                'path' => StringUtil::specialchars($archive['path']),
                'name' => StringUtil::specialchars($archive['name']),
                'size' => $this->formatBytes($archive['size']),
                'date' => Date::parse(Config::get('datimFormat'), $archive['modified']),
            ];
        }

        return $archives;
    }

    /**
     * Translates [code, params] step/warning entries via the tl_backup language file.
     *
     * @param list<array{0: string, 1: list<int|string>}> $items
     *
     * @return list<string>
     */
    private function translateCodes(array $items): array
    {
        $lang = $this->loadLanguage();

        return array_map(
            static function (array $item) use ($lang): string {
                // Escape the parameters (e.g. a restored dump's file name, which comes from
                // the uploaded archive and could carry HTML) before they go into the - plain,
                // HTML-free - step/warning strings and are rendered raw by the .html5 template.
                $params = array_map(static fn ($p) => StringUtil::specialchars((string) $p), $item[1]);

                return vsprintf($lang['step_'.$item[0]] ?? $item[0], $params);
            },
            $items,
        );
    }

    /**
     * The biggest chunk the JavaScript upload may send: safely below upload_max_filesize
     * and post_max_size (minus some multipart overhead), capped at 8 MB.
     */
    private function maxUploadChunkBytes(): int
    {
        $limit = min(
            $this->iniBytes((string) \ini_get('upload_max_filesize')),
            $this->iniBytes((string) \ini_get('post_max_size')),
        );

        return max(262144, min($limit - 131072, 8388608));
    }

    /**
     * Parses a php.ini quantity ("64M"); 0 or an empty value mean "unlimited".
     */
    private function iniBytes(string $value): int
    {
        $value = trim($value);

        if ('' === $value) {
            return PHP_INT_MAX;
        }

        $bytes = \function_exists('ini_parse_quantity')
            ? ini_parse_quantity($value)
            : (int) preg_replace('/[^0-9]/', '', $value) * match (strtoupper(substr($value, -1))) {
                'G' => 1073741824,
                'M' => 1048576,
                'K' => 1024,
                default => 1,
            };

        return $bytes > 0 ? $bytes : PHP_INT_MAX;
    }

    /**
     * Contao's own formatter: it uses the unit names and the decimal/thousands separators
     * of the back-end language, so the sizes here read exactly like those in the file
     * manager - and correctly in a non-German back end.
     */
    private function formatBytes(int $bytes): string
    {
        return System::getReadableSize($bytes);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadLanguage(): array
    {
        System::loadLanguageFile('tl_backup');

        return $GLOBALS['TL_LANG']['tl_backup'];
    }

    private function liftTimeLimit(): void
    {
        if (\function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
    }

    /**
     * Sets a short-lived, non-HttpOnly cookie echoing the token the page's JavaScript
     * generated for this click. The page polls for it to learn the download has started
     * (i.e. the - possibly lengthy - ZIP build is done) and re-enables the buttons.
     * Not security-relevant: it is merely a correlation marker, no secret.
     */
    private function withDownloadSignal(Response $response, string $token): Response
    {
        if ('' !== $token) {
            $response->headers->setCookie(
                Cookie::create('contao_backup_dl', $token, 0, '/', null, null, false, false, Cookie::SAMESITE_LAX),
            );
        }

        return $response;
    }

    /**
     * Notes an administrative action (e.g. deleting an archive from the server) in the
     * Contao log - the same audit trail the restores write to.
     */
    private function log(string $message): void
    {
        System::getContainer()->get('monolog.logger.contao')->info(
            $message,
            ['contao' => new ContaoContext(__METHOD__, ContaoContext::GENERAL)],
        );
    }

    /**
     * Logs a failed backup download to the Contao log - visible in the back end under
     * System > System log and in var/logs - so the reason (e.g. var/backups not writable,
     * a database dump error) is easy to find.
     */
    private function logBackupError(string $formSubmit, \Throwable $e): void
    {
        System::getContainer()->get('monolog.logger.contao')->error(
            \sprintf('Backup download "%s" failed: %s', $formSubmit, $e->getMessage()),
            ['contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR), 'exception' => $e],
        );
    }
}
