<?php

$GLOBALS['TL_LANG']['tl_backup']['headline']         = 'Sicherung';
$GLOBALS['TL_LANG']['tl_backup']['intro']            = 'Lade hier eine Sicherung herunter. Unter jedem Button siehst du, was genau enthalten ist. Nicht vorhandene Pfade werden übersprungen.';
$GLOBALS['TL_LANG']['tl_backup']['securityNote']     = '<strong>Wichtig:</strong> Die Sicherung enthält alle sensiblen Daten – auch die Benutzer-Passwörter (verschlüsselt gespeichert, aber nicht unknackbar). Bewahre die heruntergeladene Datei sicher und vertraulich auf.';
$GLOBALS['TL_LANG']['tl_backup']['gzipHint']         = '<strong>Hinweis:</strong> Dieser Server komprimiert die Downloads (gzip), deshalb zeigt der Fortschritt nur die Größe (MB) statt eines Prozent-Balkens – die Downloads funktionieren normal. Soll der Prozent-Balken erscheinen, in <code>public/.htaccess</code> ergänzen:<br><code>&lt;IfModule mod_setenvif.c&gt;<br>&nbsp;&nbsp;SetEnvIf Query_String &quot;do=backup&quot; no-gzip dont-vary<br>&lt;/IfModule&gt;</code>';
$GLOBALS['TL_LANG']['tl_backup']['downloadFull']     = 'Datenbank und Dateien herunterladen';
$GLOBALS['TL_LANG']['tl_backup']['downloadDatabase'] = 'Nur Datenbank herunterladen';
$GLOBALS['TL_LANG']['tl_backup']['downloadFiles']    = 'Nur Dateien herunterladen';
$GLOBALS['TL_LANG']['tl_backup']['databaseGroup']    = 'Datenbank';
$GLOBALS['TL_LANG']['tl_backup']['filesGroup']       = 'Dateien und Ordner';
$GLOBALS['TL_LANG']['tl_backup']['databaseItem']     = 'alle Tabellen als SQL-Backup';
$GLOBALS['TL_LANG']['tl_backup']['busy']             = 'Wird erstellt …';
$GLOBALS['TL_LANG']['tl_backup']['started']          = '✓ Download gestartet';
$GLOBALS['TL_LANG']['tl_backup']['done']             = '✓ Fertig';
$GLOBALS['TL_LANG']['tl_backup']['error']            = 'Fehler – bitte erneut versuchen';
