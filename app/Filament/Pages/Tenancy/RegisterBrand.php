<?php

namespace App\Filament\Pages\Tenancy;

use App\Models\Brand;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class RegisterBrand extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Add a brand';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Brand name')
                ->helperText('For example: Moggies Export Services. Each brand has its own accounts, posts and numbers.')
                ->required()
                ->maxLength(100)
                ->unique(table: Brand::class, column: 'name'),
        ]);
    }

    protected function handleRegistration(array $data): Model
    {
        $brand = Brand::create(['name' => $data['name']]);
        $brand->users()->attach(auth()->user());

        return $brand;
    }
}
