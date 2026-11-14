<?php

namespace App\Support;

use RuntimeException;

/**
 * Finds this copy's data folder (database, .env, media, storage), which lives outside the code.
 *
 * The SMM_DATA_DIR environment variable wins; otherwise the git-ignored `.data-dir` file in the
 * code folder names it. The folder must be a full path, must exist and must not be inside the code.
 */
final class DataDir
{
    public const ENV = 'SMM_DATA_DIR';

    public const FILE = '.data-dir';

    public static function resolve(string $basePath): string
    {
        $raw = getenv(self::ENV);
        if ($raw === false || trim($raw) === '') {
            $file = $basePath.DIRECTORY_SEPARATOR.self::FILE;
            if (! is_file($file)) {
                throw new RuntimeException("SocialMedia Manager data folder: no SMM_DATA_DIR setting and no .data-dir file in {$basePath}. Run scripts\\windows\\setup.ps1 first.");
            }
            $raw = (string) file_get_contents($file);
        }

        $path = trim((string) preg_replace('/^\xEF\xBB\xBF/', '', $raw));

        if (! self::isAbsolute($path)) {
            throw new RuntimeException("SocialMedia Manager data folder: '{$path}' must be a full path, such as D:\\Pravin\\Moggi\\SocialMedia_Manager\\Data.");
        }

        $real = realpath($path);
        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException("SocialMedia Manager data folder: '{$path}' does not exist.");
        }

        $base = realpath($basePath) ?: $basePath;
        if (self::isInside($real, $base)) {
            throw new RuntimeException("SocialMedia Manager data folder: '{$real}' is inside the code folder {$base}. Keep the data outside the code.");
        }

        return $real;
    }

    private static function isAbsolute(string $path): bool
    {
        return (bool) preg_match('#^([A-Za-z]:[\\\\/]|[\\\\/]{2}|/)#', $path);
    }

    private static function isInside(string $path, string $base): bool
    {
        $normal = static fn (string $p): string => rtrim(strtolower(str_replace('\\', '/', $p)), '/').'/';

        return str_starts_with($normal($path), $normal($base));
    }
}
