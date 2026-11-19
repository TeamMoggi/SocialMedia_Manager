<?php

namespace App\Domain\Library;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use UnexpectedValueException;

/**
 * Lists the owner's photos and videos in a source folder (Markting Social Media). Read-only: it never
 * writes, moves, renames or deletes anything there (spec §4).
 */
final class SourceFiles
{
    /**
     * @param  list<string>  $extensions  lower-case, without the dot
     * @return array<string, string> relative path (forward slashes) => full path, sorted by relative path
     */
    public static function list(string $root, array $extensions = ['jpg', 'jpeg', 'mp4', 'mov'], int $limit = 2000): array
    {
        $root = rtrim($root, '\\/');
        if ($root === '' || ! is_dir($root)) {
            return [];
        }

        $files = [];
        try {
            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD,
            );
            /** @var SplFileInfo $item */
            foreach ($items as $item) {
                if (! $item->isFile() || ! in_array(strtolower($item->getExtension()), $extensions, true)) {
                    continue;
                }
                $full = $item->getPathname();
                $relative = ltrim(str_replace('\\', '/', substr($full, strlen($root))), '/');
                $files[$relative] = $full;
                if (count($files) >= $limit) {
                    break;
                }
            }
        } catch (UnexpectedValueException) {
            // A folder that can't be read is skipped; the rest is still listed.
        }

        ksort($files, SORT_STRING);

        return $files;
    }
}
