<?php

namespace App\Domain\Library;

use App\Models\Brand;
use App\Models\MediaGroup;
use App\Models\MediaItem;
use Illuminate\Support\Facades\File;

/**
 * Keeps the library in step with the brand's source folders (Markting Social Media). Strictly read-only:
 * it lists, stats, hashes and reads files, and never writes, moves, renames or deletes anything there.
 *
 * A file is new or changed when its path, size or modified time changes. A file that is gone is marked
 * missing, not deleted; if the same content shows up at another path, the item moves there with its labels.
 */
final class LibraryScanner
{
    public const INDEXED = ['jpg', 'jpeg', 'png', 'webp', 'mp4', 'mov', 'webm', 'wav', 'mp3', 'docx', 'txt', 'md', 'rtf'];

    public function scan(Brand $brand): ScanReport
    {
        $runDir = app('smm.data_dir').DIRECTORY_SEPARATOR.'run';
        File::ensureDirectoryExists($runDir);
        $lock = fopen($runDir.DIRECTORY_SEPARATOR.'media-scan.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock !== false) {
                fclose($lock);
            }

            return ScanReport::busy();
        }

        try {
            return $this->run($brand);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function run(Brand $brand): ScanReport
    {
        $counts = ['added' => 0, 'changed' => 0, 'moved' => 0, 'unchanged' => 0];
        $unreadable = [];
        $unreachable = [];
        $newlyMissing = [];

        // Phase 1: list every reachable folder and mark what is gone, so that a renamed file can be matched
        // to its old item (by content) in phase 2, even when it moved to another folder.
        $listed = [];
        foreach ($brand->librarySources() as $configured) {
            $root = rtrim(trim($configured), '\\/');
            if ($root === '' || ! is_dir($root)) {
                $unreachable[] = $configured;

                continue;
            }
            $files = SourceFiles::list($root, self::INDEXED, 100000);
            $listed[$root] = $files;

            MediaItem::query()
                ->where('brand_id', $brand->id)
                ->where('source_root', $root)
                ->whereNull('missing_since')
                ->whereNotIn('relative_path', array_keys($files))
                ->each(function (MediaItem $item) use (&$newlyMissing): void {
                    $item->update(['missing_since' => now()]);
                    $newlyMissing[$item->id] = true;
                });
        }

        // Phase 2: new, changed and moved files.
        foreach ($listed as $root => $files) {
            $known = MediaItem::query()->where('brand_id', $brand->id)->where('source_root', $root)->get()->keyBy('relative_path');
            $videosPerFolder = $this->videosPerFolder(array_keys($files));

            foreach ($files as $relative => $full) {
                clearstatcache(true, $full);
                $size = @filesize($full);
                $mtime = @filemtime($full);
                if ($size === false || $mtime === false) {
                    $unreadable[] = $relative;

                    continue;
                }

                /** @var MediaItem|null $item */
                $item = $known->get($relative);
                if ($item !== null && $item->bytes === $size && $item->mtime === $mtime) {
                    if ($item->isMissing()) {
                        $item->update(['missing_since' => null]);
                    }
                    $counts['unchanged']++;

                    continue;
                }

                $hash = self::hash($full, $size);
                if ($hash === null) {
                    $unreadable[] = $relative;

                    continue;
                }

                if ($item !== null) {
                    $item->update(['bytes' => $size, 'mtime' => $mtime, 'hash' => $hash, 'missing_since' => null]
                        + $this->facts($item->kind->isAttachable(), $full));
                    $counts['changed']++;

                    continue;
                }

                $group = $this->group($brand, $root, $relative);
                $moved = MediaItem::query()
                    ->where('brand_id', $brand->id)
                    ->whereNotNull('missing_since')
                    ->where('hash', $hash)
                    ->first();
                if ($moved !== null) {
                    $moved->update([
                        'source_root' => $root, 'relative_path' => $relative, 'media_group_id' => $group->id,
                        'bytes' => $size, 'mtime' => $mtime, 'missing_since' => null,
                    ]);
                    unset($newlyMissing[$moved->id]);
                    $counts['moved']++;

                    continue;
                }

                $guess = NameLabels::guess($relative, $videosPerFolder[self::folder($relative)] ?? 1);
                MediaItem::create([
                    'brand_id' => $brand->id, 'media_group_id' => $group->id, 'source_root' => $root,
                    'relative_path' => $relative, 'kind' => $guess['kind'], 'product_range' => $guess['product_range'],
                    'ai_made' => $guess['ai_made'], 'bytes' => $size, 'mtime' => $mtime, 'hash' => $hash,
                ] + $this->facts($guess['kind']->isAttachable(), $full));
                $counts['added']++;
            }
        }

        return new ScanReport(
            added: $counts['added'],
            changed: $counts['changed'],
            moved: $counts['moved'],
            missing: count($newlyMissing),
            unchanged: $counts['unchanged'],
            unreadable: $unreadable,
            unreachable: $unreachable,
        );
    }

    /** Content hash, or null if the file can't be read in full (locked, or still being written). */
    public static function hash(string $path, int $expectedBytes): ?string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        $context = hash_init('xxh128');
        $read = 0;
        try {
            while (! feof($handle)) {
                $chunk = @fread($handle, 1048576);
                if ($chunk === false) {
                    return null;
                }
                if ($chunk === '') {
                    break;
                }
                hash_update($context, $chunk);
                $read += strlen($chunk);
            }
        } finally {
            fclose($handle);
        }

        return $read === $expectedBytes ? hash_final($context) : null;
    }

    private function facts(bool $attachable, string $full): array
    {
        if (! $attachable) {
            return [];
        }
        $facts = MediaFacts::read($full);

        return [
            'mime' => $facts['mime'], 'width' => $facts['width'], 'height' => $facts['height'],
            'duration' => $facts['duration'], 'codec' => $facts['codec'], 'fps' => $facts['fps'],
            'faststart' => $facts['faststart'], 'facts_error' => $facts['error'],
        ];
    }

    private function group(Brand $brand, string $root, string $relative): MediaGroup
    {
        $folder = self::folder($relative);

        return MediaGroup::firstOrCreate(
            ['brand_id' => $brand->id, 'source_root' => $root, 'folder' => $folder],
            ['name' => $folder === '' ? 'Top folder' : basename($folder)],
        );
    }

    public static function folder(string $relative): string
    {
        $folder = dirname($relative);

        return $folder === '.' ? '' : str_replace('\\', '/', $folder);
    }

    /** @param list<string> $paths  @return array<string, int> folder => number of videos in it */
    private function videosPerFolder(array $paths): array
    {
        $counts = [];
        foreach ($paths as $path) {
            if (in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), MediaFacts::VIDEOS, true)) {
                $folder = self::folder($path);
                $counts[$folder] = ($counts[$folder] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
