<?php

namespace App\Filament\Resources\ConnectedAccounts;

use App\Filament\Resources\ConnectedAccounts\Pages\ListConnectedAccounts;
use App\Models\ConnectedAccount;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The brand's connected social media accounts. Read-only for now: connecting comes with the
 * Facebook/Instagram and LinkedIn steps; here the owner sees each account's state and can disconnect it.
 */
class ConnectedAccountResource extends Resource
{
    protected static ?string $model = ConnectedAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static ?string $navigationLabel = 'Connected accounts';

    protected static ?string $modelLabel = 'connected account';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('network')->label('Network'),
                TextColumn::make('name')->label('Account')->searchable(),
                TextColumn::make('status')->label('Status')->badge(),
                TextColumn::make('token_expires_at')
                    ->label('Access expires')
                    ->date('j M Y')
                    ->timezone(fn (): string => Filament::getTenant()?->timezone ?? config('smm.display_timezone'))
                    ->placeholder('No expiry date'),
                TextColumn::make('last_error')->label('Last problem')->limit(60)->placeholder('None'),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->label('Disconnect')
                    ->modalHeading('Disconnect this account?')
                    ->modalDescription('The app forgets its access to this account. Posts already published stay on the network. You can connect it again later.')
                    ->modalSubmitActionLabel('Disconnect'),
            ])
            ->emptyStateHeading('No accounts connected yet')
            ->emptyStateDescription('Connecting the Facebook Page, Instagram and the LinkedIn company page comes in a later build step.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConnectedAccounts::route('/'),
        ];
    }
}
