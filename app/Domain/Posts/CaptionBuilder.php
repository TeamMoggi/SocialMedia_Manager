<?php

namespace App\Domain\Posts;

use App\Enums\Network;
use App\Enums\PostType;
use App\Models\PostTarget;

/** The caption a network version will actually carry: its own text or the main caption, plus the AI line on Facebook. */
final class CaptionBuilder
{
    public const AI_LINE = 'Visuals made with AI';

    public static function for(PostTarget $target): string
    {
        if ($target->type === PostType::InstagramStory) {
            return '';
        }
        $caption = trim((string) ($target->caption ?: $target->post->caption));
        if ($target->network === Network::FacebookPage && AiLabel::applies($target->post)) {
            $caption .= "\n\n".self::AI_LINE;
        }

        return $caption;
    }
}
