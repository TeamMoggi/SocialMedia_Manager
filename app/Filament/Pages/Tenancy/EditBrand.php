<?php

namespace App\Filament\Pages\Tenancy;

use App\Models\Brand;
use Closure;
use Filament\Forms\Components\Repeater;
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
            Repeater::make('library_sources')
                ->label('Folders the Library reads')
                ->helperText('The app only reads these folders: it never changes, moves or deletes anything in them. Empty means Markting Social Media.')
                ->simple(
                    TextInput::make('path')
                        ->label('Folder')
                        ->required()
                        ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            self::checkFolder((string) $value, $fail);
                        }),
                )
                ->addActionLabel('Add a folder')
                ->defaultItems(0),
            Repeater::make('approval_checklist')
                ->label('Brand rules to tick before approving a post')
                ->helperText('Empty means the standard five rules.')
                ->simple(TextInput::make('item')->label('Rule')->required()->maxLength(300))
                ->addActionLabel('Add a rule')
                ->defaultItems(0),
        ]);
    }

    /** A source folder must exist and must not be inside the app's own data (database, backups, media). */
    public static function checkFolder(string $path, Closure $fail): void
    {
        $real = realpath(trim($path));
        if ($real === false || ! is_dir($real)) {
            $fail('This folder does not exist on the laptop.');

            return;
        }
        $data = realpath(app('smm.data_dir')) ?: app('smm.data_dir');
        $normal = static fn (string $p): string => rtrim(strtolower(str_replace('\\', '/', $p)), '/').'/';
        if (str_starts_with($normal($real), $normal($data))) {
            $fail("This folder is inside the app's own data folder; pick a folder with your photos and videos.");
        }
    }
}
