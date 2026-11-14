<?php

namespace App\Support;

/**
 * Real posting is allowed only from the owner's running copy, the folder named "App".
 * Any other copy (Dev) always works in dry run, whatever its settings say.
 */
final class PublishGuard
{
    public static function allowsLive(string $basePath): bool
    {
        return basename(rtrim(str_replace('\\', '/', $basePath), '/')) === 'App';
    }

    public static function effectiveMode(string $configured, string $basePath): string
    {
        return $configured === 'live' && self::allowsLive($basePath) ? 'live' : 'dry-run';
    }
}
