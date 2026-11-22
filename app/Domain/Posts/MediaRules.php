<?php

namespace App\Domain\Posts;

use App\Enums\PostType;
use App\Models\MediaItem;

/**
 * Meta's limits for each kind of post (config smm.limits), as plain-words findings: "block" stops approval,
 * "warn" is shown. Files are described by their facts (MediaFacts), not opened here.
 *
 * A file: array{name: string, mime: ?string, bytes: int, width: ?int, height: ?int, duration: ?float,
 *               codec: ?string, fps: ?float, faststart: ?bool, exists: bool, isVideo: bool}
 */
final class MediaRules
{
    /** @return list<Problem> */
    public static function check(PostType $type, array $files, string $caption, ?string $link = null): array
    {
        $problems = [];
        foreach ($files as $file) {
            if (! $file['exists']) {
                $problems[] = Problem::block("The copy of '{$file['name']}' is missing; attach it again.");
            }
        }
        $files = array_values(array_filter($files, fn (array $f): bool => $f['exists']));
        $photos = array_values(array_filter($files, fn (array $f): bool => ! $f['isVideo']));
        $videos = array_values(array_filter($files, fn (array $f): bool => $f['isVideo']));

        $found = match ($type) {
            PostType::FacebookText => self::facebookText($files, $caption),
            PostType::FacebookLink => self::facebookLink($files, $link),
            PostType::FacebookPhotos => self::facebookPhotos($photos, $videos),
            PostType::FacebookVideo => self::facebookVideo($photos, $videos),
            PostType::InstagramPhoto => self::instagramPhoto($photos, $videos),
            PostType::InstagramCarousel => self::carousel($files),
            PostType::InstagramReel => self::reel($photos, $videos),
            PostType::InstagramStory => self::story($files, $caption),
        };
        array_push($problems, ...$found);

        if (in_array($type, [PostType::InstagramPhoto, PostType::InstagramCarousel, PostType::InstagramReel], true)) {
            array_push($problems, ...self::instagramCaption($caption));
        }

        return $problems;
    }

    /** @return array<string, bool> network check => passes (no block), for the Library's badges */
    public static function badges(MediaItem $item): array
    {
        if (! $item->kind->isAttachable()) {
            return [];
        }
        $file = self::fileFromItem($item);
        $fits = fn (PostType $type): bool => ! self::hasBlock(self::check($type, [$file], ''));

        return $item->kind->isVideo()
            ? ['Reel' => $fits(PostType::InstagramReel), 'Story' => $fits(PostType::InstagramStory), 'Facebook video' => $fits(PostType::FacebookVideo)]
            : ['Instagram photo' => $fits(PostType::InstagramPhoto), 'Story' => $fits(PostType::InstagramStory), 'Facebook photo' => $fits(PostType::FacebookPhotos)];
    }

    /** A library item as a rules file. PNG and WebP count as JPEG: they are saved as JPEG when attached. */
    public static function fileFromItem(MediaItem $item): array
    {
        $mime = in_array($item->mime, ['image/png', 'image/webp'], true) ? 'image/jpeg' : $item->mime;

        return [
            'name' => $item->fileName(), 'mime' => $mime, 'bytes' => (int) $item->bytes, 'width' => $item->width,
            'height' => $item->height, 'duration' => $item->duration, 'codec' => $item->codec, 'fps' => $item->fps,
            'faststart' => $item->faststart, 'exists' => ! $item->isMissing(), 'isVideo' => $item->kind->isVideo(),
        ];
    }

    /** @param list<Problem> $problems */
    public static function hasBlock(array $problems): bool
    {
        foreach ($problems as $problem) {
            if ($problem->blocks()) {
                return true;
            }
        }

        return false;
    }

    private static function facebookText(array $files, string $caption): array
    {
        $out = [];
        if (trim($caption) === '') {
            $out[] = Problem::block('A Facebook text post needs a caption.');
        }
        if ($files !== []) {
            $out[] = Problem::warn('Text posts send no photos or videos.');
        }

        return $out;
    }

    private static function facebookLink(array $files, ?string $link): array
    {
        $out = [];
        $link = trim((string) $link);
        if (! str_starts_with(strtolower($link), 'https://') || filter_var($link, FILTER_VALIDATE_URL) === false) {
            $out[] = Problem::block('A Facebook link post needs a link that starts with https://.');
        }
        if ($files !== []) {
            $out[] = Problem::warn('Link posts send no photos or videos.');
        }

        return $out;
    }

    private static function facebookPhotos(array $photos, array $videos): array
    {
        $out = [];
        if ($photos === []) {
            $out[] = Problem::block('Add at least one photo.');
        }
        if ($videos !== []) {
            $out[] = Problem::block('A Facebook photo post takes photos only; choose Video for a video.');
        }
        $limits = config('smm.limits.facebook.photo');
        foreach ($photos as $photo) {
            if (! in_array($photo['mime'], $limits['mimes'], true)) {
                $out[] = Problem::block("Facebook photo: '{$photo['name']}' is not a JPEG, PNG, GIF, BMP or TIFF.");
            }
            if ($photo['bytes'] > $limits['max_bytes']) {
                $out[] = Problem::block("Facebook photo: '{$photo['name']}' is ".self::mb($photo['bytes']).'; Facebook takes up to '.self::mb($limits['max_bytes']).'.');
            }
        }

        return $out;
    }

    private static function facebookVideo(array $photos, array $videos): array
    {
        if (count($videos) !== 1 || $photos !== []) {
            return [Problem::block('A Facebook video post takes exactly one video.')];
        }
        $video = $videos[0];
        $out = [];
        if (self::extension($video) === 'webm' || $video['mime'] === 'video/webm') {
            $out[] = Problem::warn("Facebook video: '{$video['name']}' is WebM; MP4 is the safest format for Facebook.");
        }
        if (self::unread($video)) {
            $out[] = Problem::warn("Could not read '{$video['name']}''s details; Facebook may refuse it.");
        }

        return $out;
    }

    private static function instagramPhoto(array $photos, array $videos): array
    {
        if (count($photos) !== 1 || $videos !== []) {
            return [Problem::block('An Instagram photo post takes exactly one photo.')];
        }

        return self::feedPhoto($photos[0], 'Instagram photo');
    }

    private static function carousel(array $files): array
    {
        $limits = config('smm.limits.instagram.carousel');
        $count = count($files);
        if ($count < $limits['min_items'] || $count > $limits['max_items']) {
            return [Problem::block("A carousel takes {$limits['min_items']} to {$limits['max_items']} photos or videos (now {$count}).")];
        }

        $out = [];
        foreach ($files as $file) {
            array_push($out, ...($file['isVideo']
                ? self::video($file, 'Carousel', null, (int) config('smm.limits.instagram.reel.max_bytes'), config('smm.limits.instagram.reel'), false)
                : self::feedPhoto($file, 'Carousel')));
        }

        $first = self::ratio($files[0]);
        foreach (array_slice($files, 1) as $file) {
            $ratio = self::ratio($file);
            if ($first !== null && $ratio !== null && abs($ratio - $first) > 0.01) {
                $out[] = Problem::warn("Carousel: Instagram crops every item to the first one's shape, so '{$file['name']}' will be cropped.");
                break;
            }
        }

        return $out;
    }

    private static function reel(array $photos, array $videos): array
    {
        if (count($videos) !== 1 || $photos !== []) {
            return [Problem::block('A Reel takes exactly one video.')];
        }
        $limits = config('smm.limits.instagram.reel');

        return self::video($videos[0], 'Reel', [$limits['min_seconds'], $limits['max_seconds']], $limits['max_bytes'], $limits, true);
    }

    private static function story(array $files, string $caption): array
    {
        if (count($files) !== 1) {
            return [Problem::block('A Story takes exactly one photo or video.')];
        }
        $file = $files[0];
        $out = [];
        if ($file['isVideo']) {
            $limits = config('smm.limits.instagram.story_video');
            $out = self::video($file, 'Story', [$limits['min_seconds'], $limits['max_seconds']], $limits['max_bytes'], $limits, true);
        } else {
            $out = self::jpegAndSize($file, 'Story');
            if (! self::upright($file)) {
                $out[] = Problem::warn("Story: '{$file['name']}' is not upright 9:16; Instagram may crop it or add blank space.");
            }
        }
        if (trim($caption) !== '') {
            $out[] = Problem::warn('Stories show no caption.');
        }

        return $out;
    }

    private static function feedPhoto(array $photo, string $label): array
    {
        $limits = config('smm.limits.instagram.photo');
        $out = self::jpegAndSize($photo, $label);
        $ratio = self::ratio($photo);
        if ($ratio !== null && ($ratio < $limits['min_ratio'] - 0.0005 || $ratio > $limits['max_ratio'] + 0.0005)) {
            $out[] = Problem::block("{$label}: '{$photo['name']}' is ".self::shape($photo).'; Instagram takes shapes from 4:5 (upright) to 1.91:1 (wide).');
        }
        $width = (int) $photo['width'];
        if ($width > 0 && ($width < $limits['min_width'] || $width > $limits['max_width'])) {
            $out[] = Problem::warn("{$label}: '{$photo['name']}' is {$width} pixels wide; Instagram resizes it to between {$limits['min_width']} and {$limits['max_width']}.");
        }

        return $out;
    }

    private static function jpegAndSize(array $photo, string $label): array
    {
        $out = [];
        if ($photo['mime'] !== 'image/jpeg') {
            $out[] = Problem::block("{$label}: '{$photo['name']}' is not a JPEG; Instagram takes only JPEG photos.");
        }
        $max = (int) config('smm.limits.instagram.photo.max_bytes');
        if ($photo['bytes'] > $max) {
            $out[] = Problem::block("{$label}: '{$photo['name']}' is ".self::mb($photo['bytes']).'; Instagram takes photos up to '.self::mb($max).'.');
        }

        return $out;
    }

    /** @param array{0: int|float, 1: int|float}|null $seconds  @param array{min_ratio: float, max_ratio: float} $shape */
    private static function video(array $video, string $label, ?array $seconds, int $maxBytes, array $shape, bool $uprightWarning): array
    {
        $common = config('smm.limits.instagram.video');
        $name = $video['name'];
        $out = [];

        if (! in_array(self::extension($video), ['mp4', 'mov'], true) && ! in_array($video['mime'], ['video/mp4', 'video/quicktime'], true)) {
            $out[] = Problem::block("{$label}: '{$name}' is not MP4 or MOV; Instagram takes only those.");
        }
        if ($video['bytes'] > $maxBytes) {
            $out[] = Problem::block("{$label}: '{$name}' is ".self::mb($video['bytes']).'; Instagram takes up to '.self::mb($maxBytes).'.');
        }
        if (self::unread($video)) {
            $out[] = Problem::warn("Could not read '{$name}''s details; Instagram may refuse it.");

            return $out;
        }

        $duration = $video['duration'];
        if ($seconds !== null && $duration !== null && ($duration < $seconds[0] || $duration > $seconds[1])) {
            array_unshift($out, Problem::block("{$label}: '{$name}' is ".self::length($duration).' long; Instagram takes '
                .self::length($seconds[0]).' to '.self::length($seconds[1]).'.'));
        }
        $fps = $video['fps'];
        if ($fps !== null && ($fps < $common['min_fps'] - 0.01 || $fps > $common['max_fps'] + 0.01)) {
            $out[] = Problem::block("{$label}: '{$name}' plays ".round($fps, 1)." frames a second; Instagram takes {$common['min_fps']} to {$common['max_fps']}.");
        }
        if ((int) $video['width'] > $common['max_width']) {
            $out[] = Problem::block("{$label}: '{$name}' is {$video['width']} pixels wide; Instagram takes up to {$common['max_width']}.");
        }
        if ($video['codec'] !== null && ! in_array(strtolower($video['codec']), $common['codecs'], true)) {
            $out[] = Problem::block("{$label}: '{$name}' uses the '{$video['codec']}' video format; Instagram takes H.264 or HEVC. Export it again as an H.264 MP4.");
        }
        $ratio = self::ratio($video);
        if ($ratio !== null && ($ratio < $shape['min_ratio'] || $ratio > $shape['max_ratio'])) {
            $out[] = Problem::block("{$label}: '{$name}' is ".self::shape($video).'; that shape is too narrow or too wide for Instagram.');
        } elseif ($uprightWarning && $ratio !== null && ! self::upright($video)) {
            $out[] = Problem::warn("{$label}: '{$name}' is not upright 9:16; Instagram may crop it or add blank space.");
        }
        if ($video['faststart'] === false) {
            $out[] = Problem::warn("{$label}: '{$name}' has its index at the end of the file. Instagram's rules ask for it at the front, "
                ."though it accepted such a video in our test send; if Instagram refuses it, export it again with 'fast start' on.");
        }

        return $out;
    }

    private static function instagramCaption(string $caption): array
    {
        $limits = config('smm.limits.instagram.caption');
        $stats = CaptionStats::of($caption);
        $out = [];
        if ($stats['chars'] > $limits['max_chars']) {
            $out[] = Problem::block("The caption has {$stats['chars']} characters; Instagram takes up to ".number_format($limits['max_chars']).'.');
        }
        if ($stats['hashtags'] > $limits['max_hashtags']) {
            $out[] = Problem::block("The caption has {$stats['hashtags']} hashtags; Instagram takes up to {$limits['max_hashtags']}.");
        } elseif ($stats['hashtags'] > $limits['brand_hashtags']) {
            $out[] = Problem::warn("The caption has {$stats['hashtags']} hashtags; the brand rule is up to {$limits['brand_hashtags']} specific ones.");
        }
        if ($stats['mentions'] > $limits['max_mentions']) {
            $out[] = Problem::block("The caption has {$stats['mentions']} @mentions; Instagram takes up to {$limits['max_mentions']}.");
        }

        return $out;
    }

    private static function unread(array $video): bool
    {
        return $video['duration'] === null && $video['width'] === null;
    }

    private static function ratio(array $file): ?float
    {
        return $file['width'] && $file['height'] ? $file['width'] / $file['height'] : null;
    }

    private static function upright(array $file): bool
    {
        [$min, $max] = config('smm.limits.instagram.upright');
        $ratio = self::ratio($file);

        return $ratio === null || ($ratio >= $min && $ratio <= $max);
    }

    private static function extension(array $file): string
    {
        return strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    }

    private static function shape(array $file): string
    {
        return "{$file['width']}×{$file['height']} pixels";
    }

    private static function mb(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.').' MB';
    }

    private static function length(float|int $seconds): string
    {
        if ($seconds >= 60 && fmod((float) $seconds, 60.0) < 0.05) {
            return (int) round($seconds / 60).' min';
        }
        if ($seconds >= 60) {
            return sprintf('%d min %d s', intdiv((int) $seconds, 60), (int) $seconds % 60);
        }

        return rtrim(rtrim(number_format((float) $seconds, 1), '0'), '.').' s';
    }
}
