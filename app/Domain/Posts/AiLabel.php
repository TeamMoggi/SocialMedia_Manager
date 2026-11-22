<?php

namespace App\Domain\Posts;

use App\Models\MediaItem;
use App\Models\Post;
use App\Models\PostMedia;

/**
 * The AI label rule (owner, 18 Nov 2026): when any attached file is AI-made AND shows people, Instagram gets its
 * own AI label and the Facebook caption ends with "Visuals made with AI". AI-made files without people get none.
 */
final class AiLabel
{
    public static function applies(Post $post): bool
    {
        return self::items($post)->contains(fn (MediaItem $item): bool => $item->ai_made && $item->shows_people === true);
    }

    /** @return list<MediaItem> AI-made files whose "shows people" is not answered yet (approval waits for them). */
    public static function unanswered(Post $post): array
    {
        return self::items($post)
            ->filter(fn (MediaItem $item): bool => $item->ai_made && $item->shows_people === null)
            ->values()
            ->all();
    }

    private static function items(Post $post)
    {
        return $post->media()->with('mediaItem')->get()
            ->map(fn (PostMedia $media): ?MediaItem => $media->mediaItem)
            ->filter();
    }
}
