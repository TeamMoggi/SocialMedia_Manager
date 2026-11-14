<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AccountStatus: string implements HasColor, HasLabel
{
    case Connected = 'connected';
    case Expiring = 'expiring';
    case Expired = 'expired';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Connected => 'Connected',
            self::Expiring => 'Access expires soon',
            self::Expired => 'Access expired: reconnect',
            self::Error => 'Problem: reconnect',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Connected => 'success',
            self::Expiring => 'warning',
            self::Expired, self::Error => 'danger',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return $this->color();
    }
}
