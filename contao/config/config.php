<?php

use Heimseiten\ContaoBackupBundle\BackupModule;

// Add "Sicherung" as the FIRST entry in the "System" group (before "Einstellungen").
$GLOBALS['BE_MOD']['system'] = array_merge(
    array('backup' => array('callback' => BackupModule::class)),
    $GLOBALS['BE_MOD']['system'] ?? array(),
);
