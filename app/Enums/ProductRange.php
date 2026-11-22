<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** The product range a file or post is about (the Instagram plan's weightage lines). */
enum ProductRange: string implements HasLabel
{
    case Raisins = 'raisins';
    case FrozenVegetables = 'frozen_vegetables';
    case FruitPulp = 'fruit_pulp';
    case ExportServices = 'export_services';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::Raisins => 'Raisins',
            self::FrozenVegetables => 'Frozen vegetables',
            self::FruitPulp => 'Frozen fruit pulp',
            self::ExportServices => 'Export services',
            self::General => 'General',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
