<?php

$GLOBALS['TL_LANG']['tl_backup']['headline']         = 'Backup';
$GLOBALS['TL_LANG']['tl_backup']['intro']            = 'Download a backup here. Below each button you can see exactly what it contains. Missing paths are skipped.';
$GLOBALS['TL_LANG']['tl_backup']['securityNote']     = '<strong>Important:</strong> The backup contains all sensitive data, including the user passwords (stored encrypted, but not uncrackable). Keep the downloaded file secure and confidential.';
$GLOBALS['TL_LANG']['tl_backup']['gzipHint']         = '<strong>Note:</strong> This server compresses the downloads (gzip), so the progress is only shown as size (MB) instead of a percentage bar – the downloads work normally. To get the percentage bar, add this to <code>public/.htaccess</code>:<br><code>&lt;IfModule mod_setenvif.c&gt;<br>&nbsp;&nbsp;SetEnvIf Query_String &quot;do=backup&quot; no-gzip dont-vary<br>&lt;/IfModule&gt;</code>';
$GLOBALS['TL_LANG']['tl_backup']['downloadFull']     = 'Download database and files';
$GLOBALS['TL_LANG']['tl_backup']['downloadDatabase'] = 'Download database only';
$GLOBALS['TL_LANG']['tl_backup']['downloadFiles']    = 'Download files only';
$GLOBALS['TL_LANG']['tl_backup']['databaseGroup']    = 'Database';
$GLOBALS['TL_LANG']['tl_backup']['filesGroup']       = 'Files and folders';
$GLOBALS['TL_LANG']['tl_backup']['databaseItem']     = 'all tables as SQL backup';
$GLOBALS['TL_LANG']['tl_backup']['busy']             = 'Preparing …';
$GLOBALS['TL_LANG']['tl_backup']['started']          = '✓ Download started';
$GLOBALS['TL_LANG']['tl_backup']['done']             = '✓ Done';
$GLOBALS['TL_LANG']['tl_backup']['error']            = 'Error – please try again';
