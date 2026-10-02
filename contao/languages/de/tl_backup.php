<?php

$GLOBALS['TL_LANG']['tl_backup']['headline']         = 'Sicherung';
$GLOBALS['TL_LANG']['tl_backup']['intro']            = 'Lade hier eine Sicherung herunter. Unter jedem Button siehst du, was genau enthalten ist. Nicht vorhandene Pfade werden übersprungen.';
$GLOBALS['TL_LANG']['tl_backup']['securityNote']     = '<strong>Wichtig:</strong> Die Sicherung enthält alle sensiblen Daten – auch die Benutzer-Passwörter (verschlüsselt gespeichert, aber nicht unknackbar). Bewahre die heruntergeladene Datei sicher und vertraulich auf.';
$GLOBALS['TL_LANG']['tl_backup']['gzipHint']         = '<strong>Hinweis:</strong> Dieser Server komprimiert die Downloads (gzip), deshalb zeigt der Fortschritt nur die Größe (MB) statt eines Prozent-Balkens – die Downloads funktionieren normal. Soll der Prozent-Balken erscheinen, in <code>public/.htaccess</code> ergänzen:<br><code>&lt;IfModule mod_setenvif.c&gt;<br>&nbsp;&nbsp;SetEnvIf Query_String &quot;do=backup&quot; no-gzip dont-vary<br>&lt;/IfModule&gt;</code>';
$GLOBALS['TL_LANG']['tl_backup']['downloadFull']     = 'Datenbank und Dateien';
$GLOBALS['TL_LANG']['tl_backup']['downloadDatabase'] = 'Nur Datenbank';
$GLOBALS['TL_LANG']['tl_backup']['downloadFiles']    = 'Nur Dateien';
$GLOBALS['TL_LANG']['tl_backup']['downloadButton']    = 'Herunterladen';
$GLOBALS['TL_LANG']['tl_backup']['databaseGroup']    = 'Datenbank';
$GLOBALS['TL_LANG']['tl_backup']['filesGroup']       = 'Dateien und Ordner';
$GLOBALS['TL_LANG']['tl_backup']['databaseItem']     = 'alle Tabellen als SQL-Backup';
$GLOBALS['TL_LANG']['tl_backup']['dlModeLabel']      = 'Download-Art:';
$GLOBALS['TL_LANG']['tl_backup']['dlModeJs']         = 'Mit Fortschrittsbalken auf dieser Seite';
$GLOBALS['TL_LANG']['tl_backup']['dlModeNative']     = 'Klassischer Browser-Download (läuft im Download-Manager des Browsers weiter)';
$GLOBALS['TL_LANG']['tl_backup']['busy']             = 'Wird erstellt …';
$GLOBALS['TL_LANG']['tl_backup']['started']          = '✓ Download gestartet';
$GLOBALS['TL_LANG']['tl_backup']['done']             = '✓ Fertig';
$GLOBALS['TL_LANG']['tl_backup']['error']            = 'Fehler – bitte erneut versuchen';

// Sicherung auf dem Server ablegen (statt herunterladen)
$GLOBALS['TL_LANG']['tl_backup']['storeButton']      = 'Auf dem Server speichern';
$GLOBALS['TL_LANG']['tl_backup']['storeBusy']        = 'Wird gespeichert …';
$GLOBALS['TL_LANG']['tl_backup']['storeHint']        = '<strong>Statt herunterzuladen</strong> lässt sich eine Sicherung auch direkt auf dem Server ablegen (%s) – sinnvoll bei sehr großen Installationen: Die Datei kann danach in Ruhe per FTP geholt und unten unter „Wiederherstellung" ohne Upload ausgewählt werden. <strong>Ein Ersatz für eine Sicherung außer Haus ist das nicht:</strong> Bei einem Server-Ausfall oder -Verlust ist eine dort liegende Datei genauso verloren wie die Website selbst.';
$GLOBALS['TL_LANG']['tl_backup']['storeSummaryOne']  = 'Derzeit liegt %d Archiv (%s) auf dem Server. Auswählen und löschen lässt es sich unten unter „Wiederherstellung".';
$GLOBALS['TL_LANG']['tl_backup']['storeSummaryMany'] = 'Derzeit liegen %d Archive (zusammen %s) auf dem Server. Auswählen und löschen lassen sie sich unten unter „Wiederherstellung".';
$GLOBALS['TL_LANG']['tl_backup']['storeDone']        = 'Auf dem Server gespeichert: <strong>%s</strong> – abgelegt unter %s';
$GLOBALS['TL_LANG']['tl_backup']['storeDoneShort']   = '✓ Gespeichert unter %s (%s) – die Seite wird neu geladen.';
$GLOBALS['TL_LANG']['tl_backup']['storeFailed']      = 'Das Speichern auf dem Server ist fehlgeschlagen: %s';
$GLOBALS['TL_LANG']['tl_backup']['storeFailedShort'] = '✗ Speichern fehlgeschlagen: %s';

// Installationspaket für den Contao Manager (neue Installation auf einem anderen Server)
$GLOBALS['TL_LANG']['tl_backup']['downloadPackage']     = 'Installationspaket für den Contao Manager';
$GLOBALS['TL_LANG']['tl_backup']['packageLead']         = 'Für eine neue Installation auf einem anderen Server: Bei der Einrichtung des Contao Managers unter „Theme für Contao" hochgeladen, entsteht daraus in einem Durchgang eine Kopie dieser Website – mit allen Erweiterungen, Dateien und der Datenbank.';
$GLOBALS['TL_LANG']['tl_backup']['packageDatabaseItem'] = 'alle Tabellen als SQL-Backup in <code>var/backups</code>, vom Contao Manager zum Import angeboten';
$GLOBALS['TL_LANG']['tl_backup']['packageManagerGroup'] = 'Für den Contao Manager';
$GLOBALS['TL_LANG']['tl_backup']['packageComposerItem'] = 'Paketname und Version in <code>composer.json</code>';
$GLOBALS['TL_LANG']['tl_backup']['packageThemeItem']    = '<code>theme.xml</code>, die das Archiv als Theme ausweist';
$GLOBALS['TL_LANG']['tl_backup']['packageDescription']  = 'Installationspaket von %s, erstellt am %s';
$GLOBALS['TL_LANG']['tl_backup']['packageLocalWarning'] = '<strong>Nicht im Paket enthalten</strong> sind Erweiterungen aus lokalen Quellen (im Contao Manager hochgeladen oder als Pfad-Repository eingebunden): %s. Auf einem neuen Server lassen sie sich so nicht installieren – die Einrichtung bricht dann mit einem Composer-Fehler ab.';
$GLOBALS['TL_LANG']['tl_backup']['packageSteps']        = '<p><strong>Ablauf auf dem neuen Server:</strong></p><ol>'
    .'<li>Beim Hosting eine leere Datenbank anlegen und die Domain auf den Unterordner <code>public</code> des Projektordners zeigen lassen.</li>'
    .'<li>Per FTP die <a href="https://download.contao.org/contao-manager/stable/contao-manager.phar" target="_blank" rel="noopener">contao-manager.phar</a> als <code>public/contao-manager.phar.php</code> ablegen.</li>'
    .'<li>Den Contao Manager aufrufen und ein Manager-Konto anlegen; bei der Einrichtung „Theme für Contao" wählen, das Paket hochladen und „Installieren".</li>'
    .'<li>Im Schritt „Datenbank-Verbindung" die Zugangsdaten der leeren Datenbank eintragen (Datenbank-URL oder die einzelnen Felder); der Manager prüft sie sofort und legt die <code>.env.local</code> an. Der Datenbank-Server steht in der Hosting-Verwaltung und ist bei vielen Hostern nicht <code>localhost</code>.</li>'
    .'<li>Im Schritt „Datenbank-Import" spielt „Theme importieren" den Dump ein; „Weiter" und „Datenbank prüfen" ergänzen danach die Tabellen, die ein Backup nicht enthält (etwa das System-Log), und holen übersprungene Symlinks nach. Benutzer und Passwörter entsprechen dem Stand dieser Website.</li>'
    .'</ol>'
    .'<p>Ist im Startpunkt der Website eine Domain eingetragen und läuft die Kopie unter einer anderen, ist sie dort anzupassen. Beim Hochladen liest der Contao Manager das ganze Paket zunächst im Browser ein und hält es auf dem Server kurzzeitig mehrfach vor – für sehr große Websites (mehrere GB in <code>files</code>) eignet sich deshalb eher ein Voll-Backup mit anschließender Wiederherstellung.</p>';

// Kurztexte und Abschnitts-Untertitel (die Langfassungen stecken hinter „Mehr dazu")
$GLOBALS['TL_LANG']['tl_backup']['moreInfo']          = 'Mehr dazu';
$GLOBALS['TL_LANG']['tl_backup']['sectionAutoSub']    = 'Was im Hintergrund regelmäßig gesichert wird';
$GLOBALS['TL_LANG']['tl_backup']['sectionManualSub']  = 'Herunterladen oder auf dem Server ablegen';
$GLOBALS['TL_LANG']['tl_backup']['sectionRestoreSub'] = 'Einen früheren Stand wieder einspielen';
$GLOBALS['TL_LANG']['tl_backup']['blockDatabase']     = 'Datenbank (durch Contao)';
$GLOBALS['TL_LANG']['tl_backup']['autoLead']          = 'Contao legt in <code>var/backups</code> selbstständig Datenbank-Backups an. Hier ist einstellbar, wie viele davon aufbewahrt werden.';
$GLOBALS['TL_LANG']['tl_backup']['autoFullLead']      = 'Zusätzlich lässt sich regelmäßig ein komplettes Backup aus Datenbank <em>und</em> Dateien in %s ablegen, angestoßen vom Contao-Cron.';
$GLOBALS['TL_LANG']['tl_backup']['autoFullWarnShort'] = 'Ein Voll-Backup ist ein Vielfaches eines Datenbank-Backups – zu häufig oder zu viele füllen die Festplatte. Die Voreinstellung ist deshalb sparsam.';
$GLOBALS['TL_LANG']['tl_backup']['restoreWarnShort']  = 'Die Wiederherstellung <strong>ersetzt den aktuellen Stand unwiderruflich</strong> und kann bei einem Abbruch eine unbrauchbare Installation hinterlassen. Nur durchführen, wenn im Notfall auch ohne dieses Backend ein Zugang zum Server besteht.';
$GLOBALS['TL_LANG']['tl_backup']['restoreLead']       = 'Hier lässt sich ein früher erstellter Stand wieder einspielen – ein Datenbank-Backup vom Server oder ein komplettes Backup-Archiv.';

// Abschnitte
$GLOBALS['TL_LANG']['tl_backup']['sectionAuto']      = 'Automatische Sicherungen';
$GLOBALS['TL_LANG']['tl_backup']['sectionManual']    = 'Manuelle Sicherung (Dateien und Datenbank)';
$GLOBALS['TL_LANG']['tl_backup']['sectionRestore']   = 'Wiederherstellung';

// Automatische Datenbank-Backups (Aufbewahrung)
$GLOBALS['TL_LANG']['tl_backup']['autoIntro']        = 'Contao legt in <code>var/backups</code> automatisch Datenbank-Backups an (z. B. vor Updates über den Contao Manager oder bei <code>contao:migrate</code>; auch der Download „Nur Datenbank" speichert dort eine Kopie). Hier stellst du ein, wie viele davon aufbewahrt werden – siehe <a href="https://docs.contao.org/5.x/manual/de/cli/datenbank-backups/" target="_blank" rel="noopener">Contao-Handbuch</a>.';
$GLOBALS['TL_LANG']['tl_backup']['keepMaxLabel']     = 'Maximale Anzahl aufbewahrter Backups (0 = alle behalten)';
$GLOBALS['TL_LANG']['tl_backup']['keepIntervalsLabel'] = 'Zusätzlich behalten: das jeweils älteste Backup je Zeitraum (kommagetrennt, z. B. „1D,7D,14D,1M" für 1/7/14 Tage und 1 Monat; leer = keine)';
$GLOBALS['TL_LANG']['tl_backup']['settingsDefault']  = 'Standard (ohne eigene Einstellung): %s';
$GLOBALS['TL_LANG']['tl_backup']['settingsActive']   = 'Eigene Einstellung aktiv.';
$GLOBALS['TL_LANG']['tl_backup']['settingsStandard'] = 'Es gilt die Contao-Standardeinstellung (bzw. deine config.yaml).';
$GLOBALS['TL_LANG']['tl_backup']['settingsSave']     = 'Einstellungen speichern';
$GLOBALS['TL_LANG']['tl_backup']['settingsReset']    = 'Auf Standard zurücksetzen';
$GLOBALS['TL_LANG']['tl_backup']['settingsSaved']    = 'Aufbewahrungs-Einstellungen gespeichert – sie gelten ab dem nächsten automatisch angelegten Backup.';
$GLOBALS['TL_LANG']['tl_backup']['settingsResetDone'] = 'Eigene Aufbewahrungs-Einstellung entfernt – es gilt wieder die Contao-Konfiguration.';
$GLOBALS['TL_LANG']['tl_backup']['settingsInvalid']  = 'Einstellungen nicht gespeichert: %s';
$GLOBALS['TL_LANG']['tl_backup']['autoListTitle']    = 'Aktuell gespeicherte Datenbank-Backups';
$GLOBALS['TL_LANG']['tl_backup']['autoListEmpty']    = 'Aktuell liegen keine Datenbank-Backups in var/backups.';

// Automatische Voll-Backups (Cron)
$GLOBALS['TL_LANG']['tl_backup']['sectionAutoFull']   = 'Automatische Voll-Backups';
$GLOBALS['TL_LANG']['tl_backup']['autoFullIntro']     = 'Zusätzlich zu den Datenbank-Backups lässt sich regelmäßig ein <strong>komplettes Backup</strong> (Datenbank und Dateien) in %s ablegen. Es wird vom Contao-Cron angestoßen und trägt den Namenszusatz <code>auto-backup</code> – aufgeräumt werden ausschließlich Archive mit diesem Zusatz, von Hand abgelegte oder per FTP hochgeladene bleiben unangetastet.';
$GLOBALS['TL_LANG']['tl_backup']['autoFullWarning']   = '<strong>Zu bedenken:</strong> Ein Voll-Backup ist ein Vielfaches eines Datenbank-Backups (schnell mehrere hundert MB bis GB). Zu häufig oder zu viele Archive füllen die Festplatte – und eine volle Festplatte legt die Website lahm. Deshalb ist der Platzbedarf vorab geprüft (reicht er nicht, wird der Lauf übersprungen und im System-Log vermerkt), die Voreinstellung ist sparsam. <strong>Ein Ersatz für eine Sicherung außer Haus ist auch das nicht.</strong>';
$GLOBALS['TL_LANG']['tl_backup']['autoEnabled']       = 'Automatische Voll-Backups anlegen';
$GLOBALS['TL_LANG']['tl_backup']['autoInterval']      = 'Wie oft';
$GLOBALS['TL_LANG']['tl_backup']['autoInterval_daily']   = 'täglich';
$GLOBALS['TL_LANG']['tl_backup']['autoInterval_weekly']  = 'wöchentlich';
$GLOBALS['TL_LANG']['tl_backup']['autoInterval_monthly'] = 'monatlich';
$GLOBALS['TL_LANG']['tl_backup']['autoKeep']          = 'Wie viele Archive aufbewahren (die ältesten werden gelöscht)';
$GLOBALS['TL_LANG']['tl_backup']['autoOnlyOnChange']  = 'Nur anlegen, wenn sich seit dem letzten Archiv etwas geändert hat (empfohlen – spart bei selten geänderten Seiten fast den gesamten Platz)';
$GLOBALS['TL_LANG']['tl_backup']['autoWebCron']       = 'Auch beim Web-Cron versuchen (ohne echten System-Cron). Nicht empfohlen: Das Packen läuft dann im Seitenaufruf und kann am PHP-Zeitlimit scheitern.';
$GLOBALS['TL_LANG']['tl_backup']['autoSaved']         = 'Einstellungen für die automatischen Voll-Backups gespeichert.';
$GLOBALS['TL_LANG']['tl_backup']['autoInvalid']       = 'Nicht gespeichert: %s';
$GLOBALS['TL_LANG']['tl_backup']['autoRunNow']        = 'Jetzt einmal ausführen';
$GLOBALS['TL_LANG']['tl_backup']['autoRunNowHint']    = 'Zum Prüfen der Einrichtung – es gelten dieselben Regeln wie beim Cron-Lauf.';
$GLOBALS['TL_LANG']['tl_backup']['autoRunDone']       = 'Lauf ausgeführt – Ergebnis: %s';
$GLOBALS['TL_LANG']['tl_backup']['autoStateTitle']    = 'Letzter automatischer Lauf';
$GLOBALS['TL_LANG']['tl_backup']['autoStateNever']    = 'Bisher wurde noch kein automatischer Lauf ausgeführt.';
$GLOBALS['TL_LANG']['tl_backup']['autoStatus_created']           = 'Archiv angelegt:';
$GLOBALS['TL_LANG']['tl_backup']['autoStatus_skipped_unchanged'] = 'übersprungen – seit dem letzten Archiv hat sich nichts geändert';
$GLOBALS['TL_LANG']['tl_backup']['autoStatus_needs_cli']         = 'übersprungen – dafür ist ein echter System-Cron nötig (contao:cron)';
$GLOBALS['TL_LANG']['tl_backup']['autoStatus_not_due']           = 'noch nicht fällig';
$GLOBALS['TL_LANG']['tl_backup']['autoStatus_locked']            = 'übersprungen – es lief bereits ein Backup';
$GLOBALS['TL_LANG']['tl_backup']['autoStatus_disabled']          = 'abgeschaltet';
$GLOBALS['TL_LANG']['tl_backup']['autoStatus_failed']            = 'fehlgeschlagen:';

// Wiederherstellung
$GLOBALS['TL_LANG']['tl_backup']['restoreHeadline']       = 'Wiederherstellung';
$GLOBALS['TL_LANG']['tl_backup']['restoreIntro']          = 'Spiele hier eine früher erstellte Sicherung wieder ein – entweder ein Datenbank-Backup, das auf dem Server liegt (var/backups), oder ein heruntergeladenes Backup-Archiv (ZIP). Damit lässt sich ein Backup auch in eine andere/frische Contao-Installation übertragen, wenn dieses Bundle dort installiert ist.';
$GLOBALS['TL_LANG']['tl_backup']['restoreDanger']         = 'Nutzung auf eigene Gefahr!';
$GLOBALS['TL_LANG']['tl_backup']['restoreWarning']        = 'Die Wiederherstellung <strong>ersetzt den aktuellen Stand unwiderruflich</strong>: Beim Einspielen der Datenbank werden zuerst <strong>alle Tabellen gelöscht</strong>, beim Einspielen der Dateien werden die enthaltenen Ordner <strong>komplett ersetzt</strong> (seither hinzugekommene Dateien entfallen). Schlägt die Wiederherstellung mittendrin fehl (z. B. durch ein Server-Timeout), kann eine <strong>nicht mehr funktionierende Installation</strong> zurückbleiben, die sich nur noch von Hand retten lässt. Führe eine Wiederherstellung deshalb <strong>nur</strong> durch, wenn du im Notfall auch ohne dieses Backend an den Server kommst – also <strong>Zugangsdaten zum Hosting</strong> (FTP/SSH und Datenbank) hast <strong>und weißt, wie ein Backup manuell eingespielt wird</strong>. Benutzer und Passwörter gelten danach im Stand des Backups – eventuell ist eine erneute Anmeldung nötig.';
$GLOBALS['TL_LANG']['tl_backup']['serverRestoreTitle']    = 'Datenbank-Sicherung vom Server wiederherstellen';
$GLOBALS['TL_LANG']['tl_backup']['serverRestoreExplain']  = 'Diese Datenbank-Backups liegen in var/backups (dort legt auch der Download „Nur Datenbank" eine Kopie ab).';
$GLOBALS['TL_LANG']['tl_backup']['serverRestoreEmpty']    = 'In var/backups liegen keine Datenbank-Backups.';
$GLOBALS['TL_LANG']['tl_backup']['uploadTitle']           = 'Backup-Archiv (ZIP) hochladen oder vom Server auswählen';
$GLOBALS['TL_LANG']['tl_backup']['uploadExplain']         = 'Ein mit diesem Bundle heruntergeladenes Voll- oder Dateien-Backup bzw. Installationspaket (ZIP). Der Upload erfolgt in kleinen Teilen, dadurch sind auch große Archive trotz PHP-Upload-Limits möglich.';
$GLOBALS['TL_LANG']['tl_backup']['uploadComposerHint']    = '<strong>Vorab-Hinweis:</strong> Enthält das Archiv <code>composer.json</code>/<code>composer.lock</code> und spielst du diese mit ein (z. B. beim Übertragen in eine andere/frische Installation), ist danach in der Regel ein <strong>„composer install"</strong> nötig, damit die installierten Erweiterungen exakt zum eingespielten Stand passen. Im Contao Manager: <strong>Systemwartung → Composer-Abhängigkeiten → „Installer ausführen"</strong> (danach den Manager einmal neu laden). Nach der Wiederherstellung wird dir das hier ebenfalls angezeigt.';
$GLOBALS['TL_LANG']['tl_backup']['uploadButton']          = 'Archiv hochladen';
$GLOBALS['TL_LANG']['tl_backup']['uploadBusy']            = 'Wird hochgeladen …';
$GLOBALS['TL_LANG']['tl_backup']['uploadNotZip']          = 'Bitte eine ZIP-Datei hochladen.';
$GLOBALS['TL_LANG']['tl_backup']['uploadFailed']          = 'Der Upload ist fehlgeschlagen. Ohne JavaScript gelten die PHP-Upload-Limits des Servers (%s).';
$GLOBALS['TL_LANG']['tl_backup']['uploadError']           = 'Upload fehlgeschlagen: %s';

// Archiv vom Server auswählen (statt hochladen)
$GLOBALS['TL_LANG']['tl_backup']['orLabel']               = 'oder';
$GLOBALS['TL_LANG']['tl_backup']['serverFileTitle']       = 'Archiv vom Server auswählen (ohne Upload)';
$GLOBALS['TL_LANG']['tl_backup']['serverFileExplain']     = 'Ein Archiv, das bereits auf dem Server liegt – etwa per FTP/SSH oder über die Dateiverwaltung des Hostings nach %s übertragen –, lässt sich hier direkt auswählen. Für sehr große Archive ist das der zuverlässigste Weg, weil kein Browser-Upload nötig ist. Die Datei wird dabei weder kopiert noch verschoben, sondern an Ort und Stelle gelesen; sie bleibt also auch nach der Wiederherstellung erhalten.';
$GLOBALS['TL_LANG']['tl_backup']['serverFileEmpty']       = 'Dort liegen derzeit keine ZIP-Archive. Für diesen Weg die Datei per FTP nach %s übertragen und diese Seite neu laden.';
$GLOBALS['TL_LANG']['tl_backup']['serverFileButton']      = 'Ausgewähltes Archiv übernehmen';
$GLOBALS['TL_LANG']['tl_backup']['serverFileDelete']      = 'Ausgewähltes Archiv vom Server löschen';
$GLOBALS['TL_LANG']['tl_backup']['serverFileDeleteConfirm'] = 'Das ausgewählte Archiv wird endgültig vom Server gelöscht. Fortfahren?';
$GLOBALS['TL_LANG']['tl_backup']['serverFileFailed']      = 'Das Archiv auf dem Server konnte nicht verwendet werden: %s Möglicherweise wurde die Datei inzwischen entfernt – diese Seite neu laden.';
$GLOBALS['TL_LANG']['tl_backup']['archiveReadyServer']    = 'Archiv vom Server: <strong>%s</strong> (%s) – liegt unter %s und bleibt dort liegen.';
$GLOBALS['TL_LANG']['tl_backup']['archiveErrorServer']    = 'Betroffen ist die Datei %s. Sie bleibt unverändert auf dem Server und kann per FTP ersetzt werden.';
$GLOBALS['TL_LANG']['tl_backup']['discardSelectionButton'] = 'Auswahl aufheben (Datei bleibt auf dem Server)';
$GLOBALS['TL_LANG']['tl_backup']['archiveReady']          = 'Hochgeladenes Archiv: <strong>%s</strong> (%s)';
$GLOBALS['TL_LANG']['tl_backup']['archiveInvalid']        = 'Das hochgeladene Archiv kann nicht verwendet werden: %s';
$GLOBALS['TL_LANG']['tl_backup']['archiveDatabase']       = 'Datenbank-Dump: %s (Stand: %s)';
$GLOBALS['TL_LANG']['tl_backup']['archiveNoDatabase']     = 'Kein Datenbank-Dump enthalten (reines Dateien-Backup).';
$GLOBALS['TL_LANG']['tl_backup']['archiveFiles']          = 'Dateien: %s Einträge (%s entpackt)';
$GLOBALS['TL_LANG']['tl_backup']['archiveNoFiles']        = 'Keine Dateien/Ordner enthalten (reines Datenbank-Backup).';
$GLOBALS['TL_LANG']['tl_backup']['archiveIgnored']        = '%d Einträge außerhalb der bekannten Backup-Pfade bzw. Symlinks werden ignoriert.';
$GLOBALS['TL_LANG']['tl_backup']['optDatabase']           = 'Datenbank wiederherstellen (alle Tabellen werden vorher gelöscht)';
$GLOBALS['TL_LANG']['tl_backup']['optFiles']              = 'Dateien/Ordner wiederherstellen (die enthaltenen Pfade werden komplett ersetzt)';
$GLOBALS['TL_LANG']['tl_backup']['optComposer']           = 'composer.json / composer.lock mit einspielen (danach ist „composer install" bzw. der Contao Manager nötig)';
$GLOBALS['TL_LANG']['tl_backup']['optSafety']             = 'Vorher ein Sicherheits-Backup der aktuellen Datenbank anlegen (empfohlen)';
$GLOBALS['TL_LANG']['tl_backup']['optFilesync']           = 'Danach die Dateiverwaltung synchronisieren (contao:filesync)';
$GLOBALS['TL_LANG']['tl_backup']['confirmWord']           = 'WIEDERHERSTELLEN';
$GLOBALS['TL_LANG']['tl_backup']['confirmLabel']          = 'Zur Bestätigung „%s" eintippen:';
$GLOBALS['TL_LANG']['tl_backup']['confirmWordWrong']      = 'Bitte tippe zur Bestätigung das Wort „%s" ein – es wurde nichts verändert.';
$GLOBALS['TL_LANG']['tl_backup']['restoreButtonServer']   = 'Ausgewählte Sicherung jetzt wiederherstellen';
$GLOBALS['TL_LANG']['tl_backup']['restoreButtonArchive']  = 'Archiv jetzt wiederherstellen';
$GLOBALS['TL_LANG']['tl_backup']['discardButton']         = 'Hochgeladenes Archiv verwerfen';
$GLOBALS['TL_LANG']['tl_backup']['restoreBusy']           = 'Wiederherstellung läuft – dieses Fenster nicht schließen …';
$GLOBALS['TL_LANG']['tl_backup']['noBackupSelected']      = 'Bitte zuerst eine Sicherung aus der Liste auswählen – es wurde nichts verändert.';
$GLOBALS['TL_LANG']['tl_backup']['noArchive']             = 'Es liegt kein hochgeladenes Archiv vor.';
$GLOBALS['TL_LANG']['tl_backup']['nothingSelected']       = 'Bitte auswählen, was wiederhergestellt werden soll (Datenbank und/oder Dateien) – es wurde nichts verändert.';
$GLOBALS['TL_LANG']['tl_backup']['restoreFailed']         = 'Die Wiederherstellung ist fehlgeschlagen: %s';
$GLOBALS['TL_LANG']['tl_backup']['restoreFailedSafety']   = 'Vor dem Fehler wurde ein Sicherheits-Backup angelegt: <strong>%s</strong>. Es kann oben unter „Datenbank-Sicherung vom Server wiederherstellen" oder per Konsole (contao:backup:restore) eingespielt werden.';
$GLOBALS['TL_LANG']['tl_backup']['restoreDone']           = 'Wiederherstellung abgeschlossen.';
$GLOBALS['TL_LANG']['tl_backup']['restoreDoneNote']       = 'Benutzer/Passwörter entsprechen jetzt dem Stand des Backups – eventuell ist eine erneute Anmeldung nötig. Haben sich die installierten Erweiterungen seit dem Backup geändert, anschließend „composer install" bzw. „contao:migrate" ausführen.';
$GLOBALS['TL_LANG']['tl_backup']['compatSame']            = '✓ Backup und Installation passen zusammen (Backup: Contao %s, installiert: %s).';
$GLOBALS['TL_LANG']['tl_backup']['compatOlder']           = 'Das Backup stammt von Contao %s, installiert ist %s – nach dem Einspielen die Datenbank-Migrationen ausführen lassen (Option unten, empfohlen).';
$GLOBALS['TL_LANG']['tl_backup']['compatNewer']           = 'Das Backup stammt von einer <strong>neueren</strong> Contao-Version (%s, installiert: %s). Die Datenbank wäre nach dem Einspielen neuer als der Code – die Installation kann dadurch unbrauchbar werden. Besser: die Installation zuerst aktualisieren.';
$GLOBALS['TL_LANG']['tl_backup']['compatNewerConfirm']    = 'Trotzdem fortfahren – Risiko verstanden';
$GLOBALS['TL_LANG']['tl_backup']['compatUnknown']         = 'Das Archiv enthält keine Versionsinformationen (mit einer älteren Bundle-Version erstellt) – die Kompatibilität kann nicht geprüft werden.';
$GLOBALS['TL_LANG']['tl_backup']['compatPhp']             = 'Das Backup wurde mit PHP %s erstellt, dieser Server nutzt PHP %s – beim Einspielen von composer.json/lock können Pakete inkompatibel sein.';
$GLOBALS['TL_LANG']['tl_backup']['optMigrate']            = 'Danach Datenbank-Migrationen ausführen (contao:migrate, ohne Löschungen – empfohlen)';
$GLOBALS['TL_LANG']['tl_backup']['downgradeBlocked']      = 'Wiederherstellung blockiert: Das Backup stammt von Contao %s, diese Installation nutzt %s – die Datenbank wäre neuer als der Code. Zum Erzwingen die Checkbox „Trotzdem fortfahren" aktivieren; empfohlen ist stattdessen, die Installation zuerst zu aktualisieren. Es wurde nichts verändert.';
$GLOBALS['TL_LANG']['tl_backup']['composerTitle']         = 'Noch zu erledigen: Pakete angleichen';
$GLOBALS['TL_LANG']['tl_backup']['composerDiffText']      = 'Das eingespielte composer.lock weicht vom installierten Stand ab (%d zu installieren, %d zu entfernen, %d Versionswechsel). Führe <strong>„composer install"</strong> aus, um exakt den Paket-Stand der Quelle herzustellen – Hinweise im Manager wie „manuell entfernt" sind dabei normal (Alt-Reste dieser Installation).';
$GLOBALS['TL_LANG']['tl_backup']['composerDiffNone']      = '✓ Die installierten Pakete entsprechen bereits dem eingespielten composer.lock.';
$GLOBALS['TL_LANG']['tl_backup']['composerManagerButton'] = 'Contao Manager öffnen';
$GLOBALS['TL_LANG']['tl_backup']['composerManagerSteps']  = '<ol class="restore-composer-steps"><li>Contao Manager öffnen und oben rechts auf <strong>„Systemwartung"</strong> klicken</li><li>Zum Abschnitt <strong>„Composer-Abhängigkeiten"</strong> scrollen</li><li><strong>„Installer ausführen"</strong> klicken – das führt „composer install" mit dem eingespielten composer.lock aus (nichts auf der Paketseite hinzufügen oder anwenden!)</li><li>Danach den Contao Manager einmal neu laden – die Pakete werden wieder normal angezeigt</li></ol>';
$GLOBALS['TL_LANG']['tl_backup']['composerNoManager']     = 'Kein Contao Manager in dieser Installation gefunden – „composer install" über die Konsole ausführen oder den Manager installieren (contao-manager.phar als public/contao-manager.phar.php ablegen).';
$GLOBALS['TL_LANG']['tl_backup']['healthChecking']        = 'Prüfe, ob die Website antwortet …';
$GLOBALS['TL_LANG']['tl_backup']['healthOk']              = '✓ Website antwortet (HTTP %s).';
$GLOBALS['TL_LANG']['tl_backup']['healthFail']            = '✗ Website antwortet nicht wie erwartet (%s) – bitte das Frontend prüfen.';
$GLOBALS['TL_LANG']['tl_backup']['step_safety_backup']    = 'Sicherheits-Backup der bisherigen Datenbank erstellt: %s';
$GLOBALS['TL_LANG']['tl_backup']['step_tables_dropped']   = '%d bestehende Tabellen gelöscht';
$GLOBALS['TL_LANG']['tl_backup']['step_database_restored'] = 'Datenbank-Backup eingespielt: %s';
$GLOBALS['TL_LANG']['tl_backup']['step_files_restored']   = 'Dateien/Ordner ersetzt: %s';
$GLOBALS['TL_LANG']['tl_backup']['step_caches_cleared']   = 'Caches geleert';
$GLOBALS['TL_LANG']['tl_backup']['step_filesync_done']    = 'Dateiverwaltung synchronisiert (%d Änderungen)';
$GLOBALS['TL_LANG']['tl_backup']['step_filesync_failed']  = 'Die Dateisynchronisation ist fehlgeschlagen (%s) – bitte „contao:filesync" manuell ausführen.';
$GLOBALS['TL_LANG']['tl_backup']['step_migrate_done']     = 'Datenbank-Migrationen ausgeführt (contao:migrate, ohne Löschungen)';
$GLOBALS['TL_LANG']['tl_backup']['step_migrate_failed']   = 'contao:migrate ist fehlgeschlagen (%s) – bitte manuell über Konsole oder Contao Manager ausführen.';
