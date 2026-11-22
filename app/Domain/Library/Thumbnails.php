<?php

namespace App\Domain\Library;

use App\Models\MediaItem;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Small JPEG previews of library photos, cached in <data>/thumbs (outside media/, so the nightly backup leaves
 * them out). Very large images get none, so an 8000 x 6000 PNG can't run the laptop out of memory.
 */
final class Thumbnails
{
    public const SIZE = 320;

    public const MAX_PIXELS = 40_000_000;

    public static function dir(): string
    {
        return (string) (config('smm.thumbs_dir') ?: app('smm.data_dir').DIRECTORY_SEPARATOR.'thumbs');
    }

    /** Whether a thumbnail can be made at all (cheap: no file is opened). */
    public static function possible(MediaItem $item): bool
    {
        return $item->kind->isImage() && ! $item->isMissing()
            && (int) $item->width * (int) $item->height <= self::MAX_PIXELS;
    }

    /** Path of the cached thumbnail, made now if needed; null when there can't be one. Never throws. */
    public static function for(MediaItem $item): ?string
    {
        if (! self::possible($item)) {
            return null;
        }
        $path = self::dir().DIRECTORY_SEPARATOR.$item->id.'-'.substr((string) $item->hash, 0, 8).'.jpg';
        if (is_file($path)) {
            return $path;
        }

        try {
            $data = @file_get_contents($item->fullPath());
            $image = $data === false ? false : @imagecreatefromstring($data);
            if ($image === false) {
                return null;
            }
            $width = imagesx($image);
            $height = imagesy($image);
            $scale = min(1, self::SIZE / max($width, $height));
            $thumbWidth = max(1, (int) round($width * $scale));
            $thumbHeight = max(1, (int) round($height * $scale));
            $thumb = imagecreatetruecolor($thumbWidth, $thumbHeight);
            imagefill($thumb, 0, 0, imagecolorallocate($thumb, 255, 255, 255));
            imagecopyresampled($thumb, $image, 0, 0, 0, 0, $thumbWidth, $thumbHeight, $width, $height);
            File::ensureDirectoryExists(self::dir());
            $saved = imagejpeg($thumb, $path, 80);
            imagedestroy($image);
            imagedestroy($thumb);

            return $saved ? $path : null;
        } catch (Throwable) {
            return null;
        }
    }
}
