<?php

namespace App\Filament\Pages\Tenancy;

use App\Models\Brand;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Schema;

class EditBrand extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return 'Brand settings';
    }

    public function form(Schema $schema): Schema
    {
        $zones = timezone_identifiers_list();

        return $schema->components([
            TextInput::make('name')
                ->label('Brand name')
                ->required()
                ->maxLength(100)
                ->unique(table: Brand::class, column: 'name', ignoreRecord: true),
            Select::make('timezone')
                ->label('Time zone for post times and reports')
                ->options(array_combine($zones, $zones))
                ->searchable()
                ->required(),
        ]);
    }
}
