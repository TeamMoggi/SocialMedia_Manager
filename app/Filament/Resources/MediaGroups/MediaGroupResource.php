<?php

namespace App\Filament\Resources\MediaGroups;

use App\Filament\Resources\MediaGroups\Pages\ListMediaGroups;
use App\Models\Activity;
use App\Models\MediaGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Table;

/** The Library's folders (one group per folder), so a folder like "5" can be given a meaningful name. */
class MediaGroupResource extends Resource
{
    protected static ?string $model = MediaGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    protected static ?string $navigationLabel = 'Folders';

    protected static ?string $modelLabel = 'folder';

    protected static ?int $navigationSort = 21;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextInputColumn::make('name')
                    ->label('Name')
                    ->rules(['required', 'max:150'])
                    ->afterStateUpdated(function (MediaGroup $record, mixed $state): void {
                        Activity::record($record->brand, $record, 'folder renamed', ['name' => $state]);
                    }),
                TextColumn::make('folder')->label('Folder')->placeholder('Top folder')->searchable(),
                TextColumn::make('items_count')->label('Files')->counts('items'),
            ])
            ->defaultSort('folder')
            ->paginationPageOptions([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMediaGroups::route('/'),
        ];
    }
}
