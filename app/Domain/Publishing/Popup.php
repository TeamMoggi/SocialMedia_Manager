<?php

namespace App\Domain\Publishing;

use Symfony\Component\Process\Process;
use Throwable;

/** A Windows pop-up (toast) for the owner when a post is published, late, missed or failed. Windows only; never in tests. */
final class Popup
{
    public static function show(string $title, string $text): void
    {
        if (PHP_OS_FAMILY !== 'Windows' || app()->runningUnitTests()) {
            return;
        }
        try {
            $script = base_path('scripts'.DIRECTORY_SEPARATOR.'windows'.DIRECTORY_SEPARATOR.'notify.ps1');
            $process = new Process(['powershell.exe', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-WindowStyle', 'Hidden', '-File', $script, '-Title', $title, '-Text', $text], base_path(), null, null, 30);
            $process->start();
        } catch (Throwable) {
            // The in-app notice is enough if the pop-up cannot be shown.
        }
    }
}
