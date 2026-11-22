<?php

namespace App\Domain\Posts;

/** Counts what Instagram limits in a caption: characters, hashtags and @mentions. */
final class CaptionStats
{
    /** @return array{chars: int, hashtags: int, mentions: int} */
    public static function of(string $caption): array
    {
        return [
            'chars' => mb_strlen($caption),
            'hashtags' => preg_match_all('/(?<!\w)#[\p{L}\p{N}_]+/u', $caption),
            'mentions' => preg_match_all('/(?<![\w.])@[\w.]+/u', $caption),
        ];
    }

    public static function hint(?string $caption): string
    {
        $stats = self::of((string) $caption);
        $max = number_format((int) config('smm.limits.instagram.caption.max_chars', 2200));

        return number_format($stats['chars'])." / {$max} characters · "
            .$stats['hashtags'].($stats['hashtags'] === 1 ? ' hashtag' : ' hashtags').' · '
            .$stats['mentions'].($stats['mentions'] === 1 ? ' mention' : ' mentions');
    }
}
