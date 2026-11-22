<?php

namespace App\Domain\Posts;

use App\Domain\Library\LibraryScanner;
use App\Domain\Library\MediaFacts;
use App\Models\MediaItem;
use App\Models\PostMedia;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Copies a Library file into its post's folder (<data>/media/posts/<post id>/), so what is posted can't change
 * when the original is edited or moved. PNG and WebP are saved as JPEG (Instagram takes only JPEG). The
 * original in Markting Social Media is only read, never changed.
 */
final class PostMediaCopier
{
    public static function copy(PostMedia $media): void
    {
        /** @var MediaItem|null $item */
        $item = MediaItem::find($media->media_item_id);
        if ($item === null || $item->isMissing() || ! is_file($item->fullPath())) {
            $name = $item?->fileName() ?? 'This file';
            throw new RuntimeException("'{$name}' is no longer in its folder; look for new files in the Library, then pick it again.");
        }
        if (! $item->kind->isAttachable()) {
            throw new RuntimeException("'{$item->fileName()}' is not a photo or video, so it can't go in a post.");
        }

        $old = $media->getOriginal('path');
        $source = $item->fullPath();
        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        $convert = in_array($extension, ['png', 'webp'], true);
        $relative = 'posts/'.$media->post_id.'/'.(int) $media->position.'-'.substr((string) $item->hash ?: bin2hex(random_bytes(4)), 0, 8)
            .'.'.($convert ? 'jpg' : ($extension === 'jpeg' ? 'jpg' : $extension));
        $target = app('smm.data_dir').DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        File::ensureDirectoryExists(dirname($target));

        $ok = $convert ? self::toJpeg($source, $target, $extension) : @copy($source, $target);
        if (! $ok) {
            throw new RuntimeException("'{$item->fileName()}' could not be copied into the post.");
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
            File::delete(app('smm.data_dir').DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $old));
        }
    }

    public static function remove(PostMedia $media): void
    {
        if (filled($media->path)) {
            File::delete($media->fullPath());
        }
    }

    /** Re-saves a PNG or WebP as JPEG (quality 90), with transparent areas on white. */
    private static function toJpeg(string $source, string $target, string $extension): bool
    {
        $image = $extension === 'png' ? @imagecreatefrompng($source) : @imagecreatefromwebp($source);
        if ($image === false) {
            return false;
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);
        $ok = imagejpeg($canvas, $target, 90);

        return $ok;
    }
}
