<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** What a library file is. Photos, AI images, finished videos and clips can go in posts; the rest are listed only. */
enum MediaKind: string implements HasLabel
{
    case Photo = 'photo';
    case AiImage = 'ai_image';
    case FinishedVideo = 'finished_video';
    case Clip = 'clip';
    case VoiceOver = 'voice_over';
    case Music = 'music';
    case Script = 'script';

    public function label(): string
    {
        return match ($this) {
            self::Photo => 'Photo',
            self::AiImage => 'AI image',
            self::FinishedVideo => 'Finished video',
            self::Clip => 'Video clip',
            self::VoiceOver => 'Voice-over',
            self::Music => 'Music',
            self::Script => 'Script',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function isAttachable(): bool
    {
        return $this->isImage() || $this->isVideo();
    }

    public function isImage(): bool
    {
        return in_array($this, [self::Photo, self::AiImage], true);
    }

    public function isVideo(): bool
    {
        return in_array($this, [self::FinishedVideo, self::Clip], true);
    }

    /** @return list<string> */
    public static function attachableValues(): array
    {
        return array_values(array_map(fn (self $k) => $k->value, array_filter(self::cases(), fn (self $k) => $k->isAttachable())));
    }
}
