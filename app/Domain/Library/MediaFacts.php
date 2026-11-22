<?php

namespace App\Domain\Library;

use getID3;
use Throwable;

/**
 * Reads what the networks check about a photo or video: size in pixels, length, frame rate, codec and
 * whether the video's index is at the front. Photos use GD; videos use getID3 (pure PHP, as there is no
 * ffmpeg on the laptop). Read-only, and never throws: a problem is returned as a plain-words error.
 */
final class MediaFacts
{
    public const IMAGES = ['jpg', 'jpeg', 'png', 'webp'];

    public const VIDEOS = ['mp4', 'mov', 'webm'];

    /** @return array{mime: ?string, width: ?int, height: ?int, duration: ?float, codec: ?string, fps: ?float, faststart: ?bool, error: ?string} */
    public static function read(string $path): array
    {
        $facts = ['mime' => null, 'width' => null, 'height' => null, 'duration' => null, 'codec' => null, 'fps' => null, 'faststart' => null, 'error' => null];

        if (! is_file($path) || ! is_readable($path)) {
            return ['error' => 'The file is not there or cannot be read.'] + $facts;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, self::IMAGES, true)) {
            $size = @getimagesize($path);
            if ($size === false) {
                return ['error' => 'This photo could not be read.'] + $facts;
            }

            return ['mime' => $size['mime'] ?? null, 'width' => (int) $size[0], 'height' => (int) $size[1]] + $facts;
        }

        if (in_array($extension, self::VIDEOS, true)) {
            return self::video($path, $extension, $facts);
        }

        return $facts;
    }

    private static function video(string $path, string $extension, array $facts): array
    {
        try {
            $reader = new getID3;
            $reader->option_md5_data = false;
            $reader->option_sha1_data = false;
            $info = $reader->analyze($path);
        } catch (Throwable) {
            return ['error' => "This video's details could not be read."] + $facts;
        }

        $video = $info['video'] ?? [];
        $width = isset($video['resolution_x']) ? (int) $video['resolution_x'] : null;
        $height = isset($video['resolution_y']) ? (int) $video['resolution_y'] : null;
        $duration = isset($info['playtime_seconds']) ? (float) $info['playtime_seconds'] : null;

        if (! empty($info['error']) || ($width === null && $duration === null)) {
            return ['error' => "This video's details could not be read."] + $facts;
        }

        // A phone video filmed upright is often stored sideways with a "rotate 90" note.
        if (in_array((int) ($video['rotate'] ?? 0), [90, 270], true)) {
            [$width, $height] = [$height, $width];
        }

        $codec = $video['fourcc'] ?? ($info['quicktime']['video']['codec_fourcc'] ?? null);

        return [
            'mime' => $info['mime_type'] ?? null,
            'width' => $width,
            'height' => $height,
            'duration' => $duration,
            'codec' => is_string($codec) && $codec !== '' ? $codec : null,
            'fps' => isset($video['frame_rate']) ? (float) $video['frame_rate'] : null,
            'faststart' => in_array($extension, ['mp4', 'mov'], true) ? Mp4Atoms::moovFirst($path) : null,
            'error' => null,
        ];
    }
}
