<?php

declare(strict_types=1);

namespace Heimseiten\ContaoBackupBundle;

/**
 * Any error during a restore (invalid archive, failed import, failed swap ...).
 * The message is technical/English and ends up in the log and on the result page.
 */
class RestoreException extends \RuntimeException
{
    /**
     * If a safety backup was already created before the restore failed, its file name -
     * shown to the user as the recovery anchor.
     */
    public string|null $safetyBackupName = null;
}
