<?php

declare(strict_types=1);

namespace Heimseiten\ContaoBackupBundle;

/**
 * What a restore run actually did. Steps/warnings are language-neutral codes plus
 * sprintf parameters; the backend module translates them for the result page.
 */
final class RestoreResult
{
    /**
     * @param list<array{0: string, 1: list<int|string>}> $steps    completed steps as [code, params]
     * @param list<array{0: string, 1: list<int|string>}> $warnings non-fatal problems as [code, params]
     * @param array{install: int, remove: int, change: int}|null $composerDiff difference between the
     *                                                                         restored composer.lock and
     *                                                                         the installed packages
     */
    public function __construct(
        public readonly array $steps,
        public readonly array $warnings,
        public readonly string|null $safetyBackupName,
        public readonly array|null $composerDiff = null,
    ) {
    }
}
