<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Where one network's version of a post stands in sending (part 3, SM-010, moves it past "waiting"). */
enum TargetState: string implements HasLabel
{
    case Waiting = 'waiting';
    case HandedToFacebook = 'handed_to_facebook';
    case Queued = 'queued';
    case Published = 'published';
    case Failed = 'failed';
    case Missed = 'missed';

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Not sent yet',
            self::HandedToFacebook => 'Handed to Facebook',
            self::Queued => 'Queued',
            self::Published => 'Published',
            self::Failed => 'Failed',
            self::Missed => 'Missed',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
