<?php

$GLOBALS['TL_LANG']['tl_backup']['headline']         = 'Backup';
$GLOBALS['TL_LANG']['tl_backup']['intro']            = 'Download a backup here. Below each button you can see exactly what it contains. Missing paths are skipped.';
$GLOBALS['TL_LANG']['tl_backup']['securityNote']     = '<strong>Important:</strong> The backup contains all sensitive data, including the user passwords (stored encrypted, but not uncrackable). Keep the downloaded file secure and confidential.';
$GLOBALS['TL_LANG']['tl_backup']['gzipHint']         = '<strong>Note:</strong> This server compresses the downloads (gzip), so the progress is only shown as size (MB) instead of a percentage bar – the downloads work normally. To get the percentage bar, add this to <code>public/.htaccess</code>:<br><code>&lt;IfModule mod_setenvif.c&gt;<br>&nbsp;&nbsp;SetEnvIf Query_String &quot;do=backup&quot; no-gzip dont-vary<br>&lt;/IfModule&gt;</code>';
$GLOBALS['TL_LANG']['tl_backup']['downloadFull']     = 'Database and files';
$GLOBALS['TL_LANG']['tl_backup']['downloadDatabase'] = 'Database only';
$GLOBALS['TL_LANG']['tl_backup']['downloadFiles']    = 'Files only';
$GLOBALS['TL_LANG']['tl_backup']['downloadButton']    = 'Download';
$GLOBALS['TL_LANG']['tl_backup']['databaseGroup']    = 'Database';
$GLOBALS['TL_LANG']['tl_backup']['filesGroup']       = 'Files and folders';
$GLOBALS['TL_LANG']['tl_backup']['databaseItem']     = 'all tables as SQL backup';
$GLOBALS['TL_LANG']['tl_backup']['dlModeLabel']      = 'Download method:';
$GLOBALS['TL_LANG']['tl_backup']['dlModeJs']         = 'With a progress bar on this page';
$GLOBALS['TL_LANG']['tl_backup']['dlModeNative']     = 'Classic browser download (continues in the browser\'s download manager)';
$GLOBALS['TL_LANG']['tl_backup']['busy']             = 'Preparing …';
$GLOBALS['TL_LANG']['tl_backup']['started']          = '✓ Download started';
$GLOBALS['TL_LANG']['tl_backup']['done']             = '✓ Done';
$GLOBALS['TL_LANG']['tl_backup']['error']            = 'Error – please try again';

// Storing a backup on the server (instead of downloading it)
$GLOBALS['TL_LANG']['tl_backup']['storeButton']      = 'Store on the server';
$GLOBALS['TL_LANG']['tl_backup']['storeBusy']        = 'Storing …';
$GLOBALS['TL_LANG']['tl_backup']['storeHint']        = '<strong>Instead of downloading</strong>, a backup can also be written straight to the server (%s) – useful for very large installations: the file can then be fetched per FTP at leisure and selected below under "Restore" without an upload. <strong>This does not replace an off-site backup:</strong> if the server fails or is lost, a file sitting there is gone just like the website itself.';
$GLOBALS['TL_LANG']['tl_backup']['storeSummaryOne']  = 'There is currently %d archive (%s) on the server. It can be selected and deleted below under "Restore".';
$GLOBALS['TL_LANG']['tl_backup']['storeSummaryMany'] = 'There are currently %d archives (%s in total) on the server. They can be selected and deleted below under "Restore".';
$GLOBALS['TL_LANG']['tl_backup']['storeDone']        = 'Stored on the server: <strong>%s</strong> – located at %s';
$GLOBALS['TL_LANG']['tl_backup']['storeDoneShort']   = '✓ Stored at %s (%s) – reloading the page.';
$GLOBALS['TL_LANG']['tl_backup']['storeFailed']      = 'Storing the backup on the server failed: %s';
$GLOBALS['TL_LANG']['tl_backup']['storeFailedShort'] = '✗ Storing failed: %s';

// Installation package for the Contao Manager (a new installation on another server)
$GLOBALS['TL_LANG']['tl_backup']['downloadPackage']     = 'Installation package for the Contao Manager';
$GLOBALS['TL_LANG']['tl_backup']['packageLead']         = 'For a new installation on another server: uploaded in the Contao Manager setup under "Contao Theme", it becomes a copy of this website in one pass – with all extensions, files and the database.';
$GLOBALS['TL_LANG']['tl_backup']['packageDatabaseItem'] = 'all tables as an SQL backup in <code>var/backups</code>, offered for import by the Contao Manager';
$GLOBALS['TL_LANG']['tl_backup']['packageManagerGroup'] = 'For the Contao Manager';
$GLOBALS['TL_LANG']['tl_backup']['packageComposerItem'] = 'package name and version in <code>composer.json</code>';
$GLOBALS['TL_LANG']['tl_backup']['packageThemeItem']    = '<code>theme.xml</code>, marking the archive as a theme';
$GLOBALS['TL_LANG']['tl_backup']['packageDescription']  = 'Installation package of %s, created on %s';
$GLOBALS['TL_LANG']['tl_backup']['packageLocalWarning'] = '<strong>Not included</strong> are extensions from local sources (uploaded in the Contao Manager or taken from a path repository): %s. They cannot be installed on a new server this way – the setup then stops with a Composer error.';
$GLOBALS['TL_LANG']['tl_backup']['packageSteps']        = '<p><strong>On the new server:</strong></p><ol>'
    .'<li>Hosting: an empty database, and the domain pointing to the <code>public</code> subfolder of the project directory.</li>'
    .'<li>Via FTP: the <a href="https://download.contao.org/contao-manager/stable/contao-manager.phar" target="_blank" rel="noopener">contao-manager.phar</a> stored as <code>public/contao-manager.phar.php</code>. With a <code>.env.local</code> holding the database access next to it in the project directory (not in <code>public</code>), the Manager no longer asks for it: <code>DATABASE_URL=mysql://user:password@localhost:3306/database</code> – special characters in the password URL-encoded, e.g. <code>%40</code> for <code>@</code>.</li>'
    .'<li>Contao Manager: after creating a Manager account, the setup offers "Contao Theme" – the package is uploaded there and installed with "Install".</li>'
    .'<li>In the "Database Import" step, "Import theme database" loads the dump; "Continue" and "Check database" then add the tables a backup does not contain (such as the system log). Users and passwords are those of this website.</li>'
    .'</ol>'
    .'<p>If the website root has a domain set and the copy runs under another one, it needs adjusting there. On upload, the Contao Manager first reads the whole package in the browser and briefly keeps several copies on the server – for very large websites (several GB in <code>files</code>), a full backup followed by a restore is therefore the better route.</p>';

// Short texts and section subtitles (the long versions sit behind "More about this")
$GLOBALS['TL_LANG']['tl_backup']['moreInfo']          = 'More about this';
$GLOBALS['TL_LANG']['tl_backup']['sectionAutoSub']    = 'What gets backed up regularly in the background';
$GLOBALS['TL_LANG']['tl_backup']['sectionManualSub']  = 'Download it or keep it on the server';
$GLOBALS['TL_LANG']['tl_backup']['sectionRestoreSub'] = 'Put an earlier state back in place';
$GLOBALS['TL_LANG']['tl_backup']['blockDatabase']     = 'Database (by Contao)';
$GLOBALS['TL_LANG']['tl_backup']['autoLead']          = 'Contao creates database backups in <code>var/backups</code> on its own. How many of them are kept can be set here.';
$GLOBALS['TL_LANG']['tl_backup']['autoFullLead']      = 'On top of that, a complete backup of database <em>and</em> files can be stored in %s on a schedule, triggered by the Contao cron.';
$GLOBALS['TL_LANG']['tl_backup']['autoFullWarnShort'] = 'A full backup is many times the size of a database backup – too often, or too many kept, fills the disk. The defaults are frugal for that reason.';
$GLOBALS['TL_LANG']['tl_backup']['restoreWarnShort']  = 'Restoring <strong>replaces the current state irreversibly</strong> and can leave a broken installation if it is interrupted. Only do it if the server can be reached without this back end in an emergency.';
$GLOBALS['TL_LANG']['tl_backup']['restoreLead']       = 'An earlier state can be put back in place here – a database backup from the server or a complete backup archive.';

// Sections
$GLOBALS['TL_LANG']['tl_backup']['sectionAuto']      = 'Automatic backups';
$GLOBALS['TL_LANG']['tl_backup']['sectionManual']    = 'Manual backup (files and database)';
$GLOBALS['TL_LANG']['tl_backup']['sectionRestore']   = 'Restore';

// Automatic database backups (retention)
$GLOBALS['TL_LANG']['tl_backup']['autoIntro']        = 'Contao automatically stores database backups in <code>var/backups</code> (e.g. before updates via the Contao Manager or on <code>contao:migrate</code>; the "Download database only" button also stores a copy there). Configure how many of them are kept – see the <a href="https://docs.contao.org/5.x/manual/en/cli/database-backups/" target="_blank" rel="noopener">Contao manual</a>.';
$GLOBALS['TL_LANG']['tl_backup']['keepMaxLabel']     = 'Maximum number of backups to keep (0 = keep all)';
$GLOBALS['TL_LANG']['tl_backup']['keepIntervalsLabel'] = 'Additionally keep the oldest backup per period (comma-separated, e.g. "1D,7D,14D,1M" for 1/7/14 days and 1 month; empty = none)';
$GLOBALS['TL_LANG']['tl_backup']['settingsDefault']  = 'Default (without a custom setting): %s';
$GLOBALS['TL_LANG']['tl_backup']['settingsActive']   = 'Custom setting active.';
$GLOBALS['TL_LANG']['tl_backup']['settingsStandard'] = 'The Contao default configuration applies (or your config.yaml).';
$GLOBALS['TL_LANG']['tl_backup']['settingsSave']     = 'Save settings';
$GLOBALS['TL_LANG']['tl_backup']['settingsReset']    = 'Reset to default';
$GLOBALS['TL_LANG']['tl_backup']['settingsSaved']    = 'Retention settings saved – they apply from the next automatically created backup on.';
$GLOBALS['TL_LANG']['tl_backup']['settingsResetDone'] = 'Custom retention setting removed – the Contao configuration applies again.';
$GLOBALS['TL_LANG']['tl_backup']['settingsInvalid']  = 'Settings not saved: %s';
$GLOBALS['TL_LANG']['tl_backup']['autoListTitle']    = 'Currently stored database backups';
$GLOBALS['TL_LANG']['tl_backup']['autoListEmpty']    = 'There are currently no database backups in var/backups.';

// Automatic full backups (cron)
$GLOBALS['TL_LANG']['tl_backup']['sectionAutoFull']   = 'Automatic full backups';
$GLOBALS['TL_LANG']['tl_backup']['autoFullIntro']     = 'On top of the database backups, a <strong>complete backup</strong> (database and files) can be stored in %s on a schedule. It is triggered by the Contao cron and carries the name part <code>auto-backup</code> – only archives with that part are ever cleaned up, so archives stored by hand or uploaded per FTP stay untouched.';
$GLOBALS['TL_LANG']['tl_backup']['autoFullWarning']   = '<strong>Worth considering:</strong> a full backup is many times the size of a database backup (easily hundreds of MB up to several GB). Too often, or too many kept, fills the disk – and a full disk takes the website down. The free space is therefore checked up front (if it is not enough the run is skipped and noted in the system log), and the defaults are frugal. <strong>This does not replace an off-site backup either.</strong>';
$GLOBALS['TL_LANG']['tl_backup']['autoEnabled']       = 'Create automatic full backups';
$GLOBALS['TL_LANG']['tl_backup']['autoInterval']      = 'How often';
$GLOBALS['TL_LANG']['tl_backup']['autoInterval_daily']   = 'daily';
$GLOBALS['TL_LANG']['tl_backup']['autoInterval_weekly']  = 'weekly';
$GLOBALS['TL_LANG']['tl_backup']['autoInterval_monthly'] = 'monthly';
$GLOBALS['TL_LANG']['tl_backup']['autoKeep']          = 'How many archives to keep (the oldest are deleted)';
$GLOBALS['TL_LANG']['tl_backup']['autoOnlyOnChange']  = 'Only create one when something changed since the last archive (recommended – saves nearly all of the space on sites whose files rarely change)';
$GLOBALS['TL_LANG']['tl_backup']['autoWebCron']       = 'Also attempt it on the web cron (without a real system cron). Not recommended: packing then runs inside a page request and may hit the PHP time limit.';
$GLOBALS['TL_LANG']['tl_backup']['autoSaved']         = 'Settings for the automatic full backups saved.';
$GLOBALS['TL_LANG']['tl_backup']['autoInvalid']       = 'Not saved: %s';
$GLOBALS['TL_LANG']['tl_backup']['autoRunNow']        = 'Run once now';
$GLOBALS['TL_LANG']['tl_backup']['autoRunNowHint']    = 'To verify the setup – the same rules apply as for the cron run.';
$GLOBALS['TL_LANG']['tl_backup']['autoRunDone']       = 'Run completed – result: %s';
$GLOBALS['TL_LANG']['tl_backup']['autoStateTitle']    = 'Last automatic run';
$GLOBALS['TL_LANG']['tl_backup']['autoStateNever']    = 'No automatic run has happened yet.';
$GLOBALS['TL_LANG']['tl_backup']['autoStatus_created']           = 'archive created:';
$GLOBALS['TL_LANG']['tl_backup']['autoStatus_skipped_unchanged'] = 'skipped – nothing changed since the last archive';
$GLOBALS['TL_LANG']['tl_backup']['autoStatus_needs_cli']         = 'skipped – this needs a real system cron (contao:cron)';
$GLOBALS['TL_LANG']['tl_backup']['autoStatus_not_due']           = 'not due yet';
$GLOBALS['TL_LANG']['tl_backup']['autoStatus_locked']            = 'skipped – a backup was already running';
$GLOBALS['TL_LANG']['tl_backup']['autoStatus_disabled']          = 'switched off';
$GLOBALS['TL_LANG']['tl_backup']['autoStatus_failed']            = 'failed:';

// Restore
$GLOBALS['TL_LANG']['tl_backup']['restoreHeadline']       = 'Restore';
$GLOBALS['TL_LANG']['tl_backup']['restoreIntro']          = 'Restore a previously created backup here – either a database backup stored on the server (var/backups) or a downloaded backup archive (ZIP). This also lets you move a backup into another/fresh Contao installation, as long as this bundle is installed there.';
$GLOBALS['TL_LANG']['tl_backup']['restoreDanger']         = 'Use at your own risk!';
$GLOBALS['TL_LANG']['tl_backup']['restoreWarning']        = 'Restoring <strong>irreversibly replaces the current state</strong>: when the database is restored, <strong>all tables are dropped first</strong>; when files are restored, the contained folders are <strong>replaced completely</strong> (files added since then are gone). If the restore fails half-way (e.g. a server timeout), you can be left with a <strong>broken installation</strong> that can only be rescued by hand. Therefore only restore if you can reach the server without this back end in an emergency – i.e. you have the <strong>hosting credentials</strong> (FTP/SSH and database) <strong>and know how to restore a backup manually</strong>. Users and passwords are back at the state of the backup afterwards – you may have to log in again.';
$GLOBALS['TL_LANG']['tl_backup']['serverRestoreTitle']    = 'Restore a database backup from the server';
$GLOBALS['TL_LANG']['tl_backup']['serverRestoreExplain']  = 'These database backups live in var/backups (the "Download database only" button also stores a copy there).';
$GLOBALS['TL_LANG']['tl_backup']['serverRestoreEmpty']    = 'There are no database backups in var/backups.';
$GLOBALS['TL_LANG']['tl_backup']['uploadTitle']           = 'Upload a backup archive (ZIP) or select one on the server';
$GLOBALS['TL_LANG']['tl_backup']['uploadExplain']         = 'A full or files backup or an installation package (ZIP) downloaded with this bundle. The upload is sent in small chunks, so even huge archives work despite PHP upload limits.';
$GLOBALS['TL_LANG']['tl_backup']['uploadComposerHint']    = '<strong>Heads-up:</strong> If the archive contains <code>composer.json</code>/<code>composer.lock</code> and you restore them (e.g. when moving to another/fresh installation), a <strong>"composer install"</strong> is usually needed afterwards so the installed extensions exactly match the restored state. In the Contao Manager: <strong>System maintenance → Composer dependencies → "Run installer"</strong> (then reload the manager once). This is shown here again after the restore.';
$GLOBALS['TL_LANG']['tl_backup']['uploadButton']          = 'Upload archive';
$GLOBALS['TL_LANG']['tl_backup']['uploadBusy']            = 'Uploading …';
$GLOBALS['TL_LANG']['tl_backup']['uploadNotZip']          = 'Please upload a ZIP file.';
$GLOBALS['TL_LANG']['tl_backup']['uploadFailed']          = 'The upload failed. Without JavaScript the server\'s PHP upload limits apply (%s).';
$GLOBALS['TL_LANG']['tl_backup']['uploadError']           = 'Upload failed: %s';

// Selecting an archive that is already on the server (instead of uploading one)
$GLOBALS['TL_LANG']['tl_backup']['orLabel']               = 'or';
$GLOBALS['TL_LANG']['tl_backup']['serverFileTitle']       = 'Select an archive on the server (no upload)';
$GLOBALS['TL_LANG']['tl_backup']['serverFileExplain']     = 'An archive that is already on the server – transferred to %s per FTP/SSH or with the hosting file manager – can be selected here directly. For very large archives this is the most reliable route, as no browser upload is involved. The file is neither copied nor moved but read where it lies, so it remains available after the restore as well.';
$GLOBALS['TL_LANG']['tl_backup']['serverFileEmpty']       = 'There are currently no ZIP archives there. To use this route, transfer the file to %s per FTP and reload this page.';
$GLOBALS['TL_LANG']['tl_backup']['serverFileButton']      = 'Use the selected archive';
$GLOBALS['TL_LANG']['tl_backup']['serverFileDelete']      = 'Delete the selected archive from the server';
$GLOBALS['TL_LANG']['tl_backup']['serverFileDeleteConfirm'] = 'The selected archive will be permanently deleted from the server. Continue?';
$GLOBALS['TL_LANG']['tl_backup']['serverFileFailed']      = 'The archive on the server could not be used: %s The file may have been removed meanwhile – please reload this page.';
$GLOBALS['TL_LANG']['tl_backup']['archiveReadyServer']    = 'Archive on the server: <strong>%s</strong> (%s) – located at %s, where it stays.';
$GLOBALS['TL_LANG']['tl_backup']['archiveErrorServer']    = 'This concerns the file %s. It is left untouched on the server and can be replaced per FTP.';
$GLOBALS['TL_LANG']['tl_backup']['discardSelectionButton'] = 'Clear the selection (the file stays on the server)';
$GLOBALS['TL_LANG']['tl_backup']['archiveReady']          = 'Uploaded archive: <strong>%s</strong> (%s)';
$GLOBALS['TL_LANG']['tl_backup']['archiveInvalid']        = 'The uploaded archive cannot be used: %s';
$GLOBALS['TL_LANG']['tl_backup']['archiveDatabase']       = 'Database dump: %s (created: %s)';
$GLOBALS['TL_LANG']['tl_backup']['archiveNoDatabase']     = 'No database dump included (files-only backup).';
$GLOBALS['TL_LANG']['tl_backup']['archiveFiles']          = 'Files: %s entries (%s unpacked)';
$GLOBALS['TL_LANG']['tl_backup']['archiveNoFiles']        = 'No files/folders included (database-only backup).';
$GLOBALS['TL_LANG']['tl_backup']['archiveIgnored']        = '%d entries outside the known backup paths or symlinks are ignored.';
$GLOBALS['TL_LANG']['tl_backup']['optDatabase']           = 'Restore the database (all tables are dropped first)';
$GLOBALS['TL_LANG']['tl_backup']['optFiles']              = 'Restore the files/folders (the contained paths are replaced completely)';
$GLOBALS['TL_LANG']['tl_backup']['optComposer']           = 'Also restore composer.json / composer.lock (requires "composer install" or the Contao Manager afterwards)';
$GLOBALS['TL_LANG']['tl_backup']['optSafety']             = 'Create a safety backup of the current database first (recommended)';
$GLOBALS['TL_LANG']['tl_backup']['optFilesync']           = 'Synchronize the file manager afterwards (contao:filesync)';
$GLOBALS['TL_LANG']['tl_backup']['confirmWord']           = 'RESTORE';
$GLOBALS['TL_LANG']['tl_backup']['confirmLabel']          = 'Type "%s" to confirm:';
$GLOBALS['TL_LANG']['tl_backup']['confirmWordWrong']      = 'Please type the word "%s" to confirm – nothing has been changed.';
$GLOBALS['TL_LANG']['tl_backup']['restoreButtonServer']   = 'Restore the selected backup now';
$GLOBALS['TL_LANG']['tl_backup']['restoreButtonArchive']  = 'Restore the archive now';
$GLOBALS['TL_LANG']['tl_backup']['discardButton']         = 'Discard the uploaded archive';
$GLOBALS['TL_LANG']['tl_backup']['restoreBusy']           = 'Restoring – do not close this window …';
$GLOBALS['TL_LANG']['tl_backup']['noBackupSelected']      = 'Please select a backup from the list first – nothing has been changed.';
$GLOBALS['TL_LANG']['tl_backup']['noArchive']             = 'There is no uploaded archive.';
$GLOBALS['TL_LANG']['tl_backup']['nothingSelected']       = 'Please select what to restore (database and/or files) – nothing has been changed.';
$GLOBALS['TL_LANG']['tl_backup']['restoreFailed']         = 'The restore failed: %s';
$GLOBALS['TL_LANG']['tl_backup']['restoreFailedSafety']   = 'A safety backup was created before the error: <strong>%s</strong>. It can be restored above under "Restore a database backup from the server" or via the console (contao:backup:restore).';
$GLOBALS['TL_LANG']['tl_backup']['restoreDone']           = 'Restore completed.';
$GLOBALS['TL_LANG']['tl_backup']['restoreDoneNote']       = 'Users/passwords are now back at the state of the backup – you may have to log in again. If the installed extensions changed since the backup, run "composer install" or "contao:migrate" afterwards.';
$GLOBALS['TL_LANG']['tl_backup']['compatSame']            = '✓ Backup and installation match (backup: Contao %s, installed: %s).';
$GLOBALS['TL_LANG']['tl_backup']['compatOlder']           = 'The backup stems from Contao %s, installed is %s – let the database migrations run after restoring (option below, recommended).';
$GLOBALS['TL_LANG']['tl_backup']['compatNewer']           = 'The backup stems from a <strong>newer</strong> Contao version (%s, installed: %s). After restoring, the database would be newer than the code – the installation may become unusable. Better: update this installation first.';
$GLOBALS['TL_LANG']['tl_backup']['compatNewerConfirm']    = 'Proceed anyway – I understand the risk';
$GLOBALS['TL_LANG']['tl_backup']['compatUnknown']         = 'The archive contains no version information (created with an older bundle version) – compatibility cannot be checked.';
$GLOBALS['TL_LANG']['tl_backup']['compatPhp']             = 'The backup was created with PHP %s, this server runs PHP %s – restoring composer.json/lock may yield incompatible packages.';
$GLOBALS['TL_LANG']['tl_backup']['optMigrate']            = 'Run the database migrations afterwards (contao:migrate, without deletes – recommended)';
$GLOBALS['TL_LANG']['tl_backup']['downgradeBlocked']      = 'Restore blocked: the backup stems from Contao %s, this installation runs %s – the database would be newer than the code. To force it, tick the "Proceed anyway" checkbox; updating this installation first is recommended instead. Nothing has been changed.';
$GLOBALS['TL_LANG']['tl_backup']['composerTitle']         = 'Still to do: align the packages';
$GLOBALS['TL_LANG']['tl_backup']['composerDiffText']      = 'The restored composer.lock differs from the installed packages (%d to install, %d to remove, %d version changes). Run <strong>"composer install"</strong> to get exactly the source package state – manager hints like "manually removed" are normal (leftovers of this installation).';
$GLOBALS['TL_LANG']['tl_backup']['composerDiffNone']      = '✓ The installed packages already match the restored composer.lock.';
$GLOBALS['TL_LANG']['tl_backup']['composerManagerButton'] = 'Open the Contao Manager';
$GLOBALS['TL_LANG']['tl_backup']['composerManagerSteps']  = '<ol class="restore-composer-steps"><li>Open the Contao Manager and click <strong>"System maintenance"</strong> (top right)</li><li>Scroll to the <strong>"Composer dependencies"</strong> section</li><li>Click <strong>"Run installer"</strong> – this executes "composer install" with the restored composer.lock (do not add or apply anything on the packages page!)</li><li>Then reload the Contao Manager once – the packages are shown normally again</li></ol>';
$GLOBALS['TL_LANG']['tl_backup']['composerNoManager']     = 'No Contao Manager found in this installation – run "composer install" on the console or install the manager (put contao-manager.phar as public/contao-manager.phar.php).';
$GLOBALS['TL_LANG']['tl_backup']['healthChecking']        = 'Checking whether the website responds …';
$GLOBALS['TL_LANG']['tl_backup']['healthOk']              = '✓ Website responds (HTTP %s).';
$GLOBALS['TL_LANG']['tl_backup']['healthFail']            = '✗ Website does not respond as expected (%s) – please check the front end.';
$GLOBALS['TL_LANG']['tl_backup']['step_safety_backup']    = 'Created a safety backup of the previous database: %s';
$GLOBALS['TL_LANG']['tl_backup']['step_tables_dropped']   = 'Dropped %d existing tables';
$GLOBALS['TL_LANG']['tl_backup']['step_database_restored'] = 'Restored the database backup: %s';
$GLOBALS['TL_LANG']['tl_backup']['step_files_restored']   = 'Replaced the files/folders: %s';
$GLOBALS['TL_LANG']['tl_backup']['step_caches_cleared']   = 'Cleared the caches';
$GLOBALS['TL_LANG']['tl_backup']['step_filesync_done']    = 'Synchronized the file manager (%d changes)';
$GLOBALS['TL_LANG']['tl_backup']['step_filesync_failed']  = 'The file synchronization failed (%s) – please run "contao:filesync" manually.';
$GLOBALS['TL_LANG']['tl_backup']['step_migrate_done']     = 'Ran the database migrations (contao:migrate, without deletes)';
$GLOBALS['TL_LANG']['tl_backup']['step_migrate_failed']   = 'contao:migrate failed (%s) – please run it manually via console or Contao Manager.';
