<?php

declare(strict_types=1);

namespace Heimseiten\ContaoBackupBundle;

use Contao\BackendModule;
use Contao\BackendUser;
use Contao\CoreBundle\Exception\AccessDeniedException;
use Contao\CoreBundle\Exception\ResponseException;
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

        switch (Input::post('FORM_SUBMIT')) {
            case 'tl_backup_full':
                throw new ResponseException($this->withDownloadSignal($downloader->createFullResponse(), $token));

            case 'tl_backup_database':
                throw new ResponseException($this->withDownloadSignal($downloader->createDatabaseResponse(), $token));

            case 'tl_backup_files':
                throw new ResponseException($this->withDownloadSignal($downloader->createFilesResponse(), $token));
        }

        System::loadLanguageFile('tl_backup');
        $lang = $GLOBALS['TL_LANG']['tl_backup'];

        $this->Template->headline = $lang['headline'];
        $this->Template->intro = $lang['intro'];
        $this->Template->securityNote = $lang['securityNote'];
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
}
