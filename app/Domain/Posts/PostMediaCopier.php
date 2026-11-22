<?php

namespace App\Domain\Posts;

use App\Domain\Library\LibraryScanner;
use App\Domain\Library\MediaFacts;
use App\Models\MediaItem;
use App\Models\PostMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

/**
 * Copies a Library file into its post's folder (<data>/media/posts/<post id>/), so what is posted can't change
 * when the original is edited or moved. PNG and WebP are saved as JPEG (Instagram takes only JPEG). The
 * original in Markting Social Media is only read, never changed.
 *
 * A copy that can't be made is a plain-words validation error, so the post's save is undone as a whole (the
 * post pages save in one database transaction). Copies are deleted only once that save is committed.
 */
final class PostMediaCopier
{
    public static function copy(PostMedia $media): void
    {
        /** @var MediaItem|null $item */
        $item = MediaItem::find($media->media_item_id);
        if ($item === null || $item->isMissing() || ! is_file($item->fullPath())) {
            $name = $item?->fileName() ?? 'This file';
            self::fail("'{$name}' is no longer in its folder; look for new files in the Library, then pick it again.");
        }
        if (! $item->kind->isAttachable()) {
            self::fail("'{$item->fileName()}' is not a photo or video, so it can't go in a post.");
        }

        $old = $media->getOriginal('path');
        $source = $item->fullPath();
        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        $convert = in_array($extension, ['png', 'webp'], true);
        $relative = 'posts/'.$media->post_id.'/'.(int) $media->position.'-'.substr((string) $item->hash ?: bin2hex(random_bytes(4)), 0, 8)
            .'.'.($convert ? 'jpg' : ($extension === 'jpeg' ? 'jpg' : $extension));
        $target = self::absolute($relative);
        File::ensureDirectoryExists(dirname($target));

        if ($convert) {
            self::toJpeg($item, $source, $target);
        } elseif (! @copy($source, $target)) {
            self::fail("'{$item->fileName()}' could not be copied into the post.");
        }

        $facts = MediaFacts::read($target);
        $bytes = (int) filesize($target);
        $media->fill([
            'path' => $relative,
            'mime' => $facts['mime'] ?? $item->mime,
            'bytes' => $bytes,
            'width' => $facts['width'],
            'height' => $facts['height'],
            'duration' => $facts['duration'],
            'codec' => $facts['codec'],
            'fps' => $facts['fps'],
            'faststart' => $facts['faststart'],
            'hash' => LibraryScanner::hash($target, $bytes),
        ]);

        if (filled($old) && $old !== $relative) {
            $oldPath = self::absolute($old);
            DB::afterCommit(fn () => File::delete($oldPath));
        }
    }

    public static function remove(PostMedia $media): void
    {
        if (filled($media->path)) {
            $path = $media->fullPath();
            DB::afterCommit(fn () => File::delete($path));
        }
    }

    /** Whether a picture is small enough to be turned into JPEG without running the laptop out of memory. */
    public static function convertible(?int $width, ?int $height): bool
    {
        return (int) $width * (int) $height <= (int) config('smm.limits.max_convert_pixels', 40_000_000);
    }

    /** Re-saves a PNG or WebP as JPEG (quality 90), with transparent areas on white. */
    private static function toJpeg(MediaItem $item, string $source, string $target): void
    {
        $size = @getimagesize($source);
        if ($size === false) {
            self::fail("'{$item->fileName()}' could not be read as a picture.");
        }
        if (! self::convertible($size[0], $size[1])) {
            self::fail("'{$item->fileName()}' is too large to post ({$size[0]}×{$size[1]} pixels); save a smaller copy, for example 2000 pixels wide, and pick that.");
        }
        $data = @file_get_contents($source);
        $image = $data === false ? false : @imagecreatefromstring($data);
        if ($image === false) {
            self::fail("'{$item->fileName()}' could not be read as a picture.");
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);
        if (! imagejpeg($canvas, $target, 90)) {
            self::fail("'{$item->fileName()}' could not be saved as JPEG.");
        }
    }

    private static function absolute(string $relative): string
    {
        return app('smm.data_dir').DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['data.media' => $message]);
    }
}
