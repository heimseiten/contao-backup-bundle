<?php

declare(strict_types=1);

namespace Heimseiten\ContaoBackupBundle;

use Contao\BackendModule;
use Contao\BackendUser;
use Contao\CoreBundle\Exception\AccessDeniedException;
use Contao\CoreBundle\Exception\ResponseException;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\Environment;
use Contao\Input;
use Contao\StringUtil;
use Contao\System;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Backend module "Sicherung": download a full backup of the database and/or the
 * relevant files/folders. Administrators only.
 */
class BackupModule extends BackendModule
{
    protected $strTemplate = 'be_backup';

    protected function compile(): void
    {
        if (!BackendUser::getInstance()->isAdmin) {
            throw new AccessDeniedException('Only administrators may download backups.');
        }

        $downloader = System::getContainer()->get(BackupDownloader::class);

        // The download forms post the request token, which Contao validates for us.
        $token = (string) Input::post('download_token');
        $formSubmit = (string) Input::post('FORM_SUBMIT');

        // Tiny streamed probe so the front end can detect a compressing proxy (which strips the
        // size headers) before any real download and show a hint.
        if ('tl_backup_probe' === $formSubmit) {
            throw new ResponseException($downloader->createProbeResponse());
        }

        if (\in_array($formSubmit, ['tl_backup_full', 'tl_backup_database', 'tl_backup_files'], true)) {
            try {
                $response = match ($formSubmit) {
                    'tl_backup_full' => $downloader->createFullResponse(),
                    'tl_backup_database' => $downloader->createDatabaseResponse(),
                    'tl_backup_files' => $downloader->createFilesResponse(),
                };
            } catch (\Throwable $e) {
                // Make the reason for a failed backup easy to find (e.g. var/backups not
                // writable): log it to the Contao log and re-throw so Contao shows the error.
                $this->logBackupError($formSubmit, $e);

                throw $e;
            }

            throw new ResponseException($this->withDownloadSignal($response, $token));
        }

        System::loadLanguageFile('tl_backup');
        $lang = $GLOBALS['TL_LANG']['tl_backup'];

        $this->Template->headline = $lang['headline'];
        $this->Template->intro = $lang['intro'];
        $this->Template->securityNote = $lang['securityNote'];
        $this->Template->gzipHint = $lang['gzipHint'];
        $this->Template->fullLabel = $lang['downloadFull'];
        $this->Template->databaseLabel = $lang['downloadDatabase'];
        $this->Template->filesLabel = $lang['downloadFiles'];
        $this->Template->databaseItem = $lang['databaseItem'];
        $this->Template->databaseGroup = $lang['databaseGroup'];
        $this->Template->filesGroup = $lang['filesGroup'];
        $this->Template->busyLabel = $lang['busy'];
        $this->Template->startedLabel = $lang['started'];
        $this->Template->doneLabel = $lang['done'];
        $this->Template->errorLabel = $lang['error'];
        $this->Template->filePaths = BackupDownloader::PATHS;
        $this->Template->action = StringUtil::ampersand(Environment::get('requestUri'));
        $this->Template->requestToken = System::getContainer()->get('contao.csrf.token_manager')->getDefaultTokenValue();
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
