<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Where a post stands before sending. Sending states live on each network's version (TargetState). */
enum PostState: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case AwaitingApproval = 'awaiting_approval';
    case Approved = 'approved';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::AwaitingApproval => 'Waiting for approval',
            self::Approved => 'Approved',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    /** Calendar colour. */
    public function color(): string
    {
        return match ($this) {
            self::Draft => '#94a3b8',
            self::AwaitingApproval => '#d97706',
            self::Approved => '#16a34a',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::AwaitingApproval => 'warning',
            self::Approved => 'success',
        };
    }
}
