<?php

namespace App\Domain\Publishing;

use Throwable;

/** A Windows pop-up (toast) for the owner when a post is published, late, missed or failed. Windows only; never in tests. */
final class Popup
{
    /** @param string|null $script another script to run instead of notify.ps1 (for checks) */
    public static function show(string $title, string $text, ?string $script = null): void
    {
        if (PHP_OS_FAMILY !== 'Windows' || (app()->runningUnitTests() && $script === null)) {
            return;
        }
        try {
            $script ??= base_path('scripts'.DIRECTORY_SEPARATOR.'windows'.DIRECTORY_SEPARATOR.'notify.ps1');
            $clean = fn (string $value): string => str_replace(['"', '%', "\r", "\n"], ["'", '', ' ', ' '], $value);
            // "start /B" lets PowerShell outlive this PHP process; a Symfony Process would be killed when it is destroyed.
            $command = 'start "" /B powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "'.$script.'" -Title "'.$clean($title).'" -Text "'.$clean($text).'"';
            pclose(popen($command, 'r'));
        } catch (Throwable) {
            // The in-app notice is enough if the pop-up cannot be shown.
        }
    }
}
