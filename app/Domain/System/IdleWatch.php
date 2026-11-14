<?php

namespace App\Domain\System;

/**
 * The app runs only while it is used: every page request touches <data>/run/last-activity, and
 * smm:serve stops the server once nobody has used it for the idle limit (30 minutes by default).
 */
final class IdleWatch
{
    public static function activityFile(): string
    {
        return app('smm.data_dir').DIRECTORY_SEPARATOR.'run'.DIRECTORY_SEPARATOR.'last-activity';
    }

    public static function touch(): void
    {
        $file = self::activityFile();
        if (! is_dir(dirname($file))) {
            @mkdir(dirname($file), 0777, true);
        }
        @touch($file);
    }

    public static function shouldStop(int $lastActivity, int $now, int $idleMinutes): bool
    {
        return $now - $lastActivity >= $idleMinutes * 60;
    }
}
