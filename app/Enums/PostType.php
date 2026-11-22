<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** What a post's version on one network is: the kinds the app can publish (spec §2). */
enum PostType: string implements HasLabel
{
    case FacebookText = 'facebook_text';
    case FacebookLink = 'facebook_link';
    case FacebookPhotos = 'facebook_photos';
    case FacebookVideo = 'facebook_video';
    case InstagramPhoto = 'instagram_photo';
    case InstagramCarousel = 'instagram_carousel';
    case InstagramReel = 'instagram_reel';
    case InstagramStory = 'instagram_story';

    public function network(): Network
    {
        return str_starts_with($this->value, 'facebook_') ? Network::FacebookPage : Network::Instagram;
    }

    public function label(): string
    {
        return match ($this) {
            self::FacebookText => 'Text',
            self::FacebookLink => 'Link',
            self::FacebookPhotos => 'Photo or photos',
            self::FacebookVideo => 'Video',
            self::InstagramPhoto => 'Photo',
            self::InstagramCarousel => 'Carousel (2 to 20 photos or videos)',
            self::InstagramReel => 'Reel',
            self::InstagramStory => 'Story',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    /** @return array<string, string> value => label, for one network */
    public static function forNetwork(Network $network): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            if ($case->network() === $network) {
                $out[$case->value] = $case->label();
            }
        }

        return $out;
    }
}
