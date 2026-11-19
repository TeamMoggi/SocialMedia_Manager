<?php

namespace App\Filament\Pages;

use App\Domain\Relay\RelayFolder;
use App\Models\Setting;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * The hidden Hostinger folder Instagram downloads photos and videos from: FTP details (the password is
 * stored locked and never shown) and the "Test the folder" button.
 */
class RelaySettings extends Page
{
    protected string $view = 'filament.pages.relay-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCloudArrowUp;

    protected static ?string $navigationLabel = 'Hostinger folder';

    protected static ?string $title = 'Hostinger folder';

    protected static ?string $slug = 'hostinger-folder';

    protected static ?int $navigationSort = 81;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('saveRelay')
                ->label('Folder details')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->modalHeading('Hostinger folder')
                ->modalDescription('The FTP account you made in hPanel for the hidden folder. The password is stored locked on this laptop and never shown again. Never paste it into a chat.')
                ->fillForm(fn (): array => [
                    'host' => Setting::get('relay.host'),
                    'port' => Setting::get('relay.port', 21),
                    'username' => Setting::get('relay.username'),
                    'public_url' => Setting::get('relay.public_url'),
                    'ftps' => (bool) Setting::get('relay.ftps', true),
                ])
                ->schema([
                    TextInput::make('host')->label('FTP server')->required(),
                    TextInput::make('port')->label('Port')->numeric()->default(21)->required(),
                    TextInput::make('username')->label('FTP user')->required(),
                    TextInput::make('password')
                        ->label('FTP password')
                        ->password()
                        ->helperText('Leave empty to keep the saved password.')
                        ->required(fn (): bool => Setting::getSecret('relay.password') === null),
                    TextInput::make('public_url')
                        ->label('Public address of the folder')
                        ->placeholder('https://moggiesinternational.com/<folder>/')
                        ->url()
                        ->startsWith(['https://'])
                        ->required(),
                    Toggle::make('ftps')->label('Encrypted FTP (FTPS)')->default(true),
                ])
                ->action(function (array $data): void {
                    Setting::put('relay.host', trim($data['host']));
                    Setting::put('relay.port', (int) $data['port']);
                    Setting::put('relay.username', trim($data['username']));
                    Setting::put('relay.public_url', rtrim(trim($data['public_url']), '/').'/');
                    Setting::put('relay.ftps', (bool) $data['ftps']);
                    if (filled($data['password'] ?? null)) {
                        Setting::putSecret('relay.password', $data['password']);
                    }
                    Setting::put('relay.saved_at', CarbonImmutable::now('UTC')->toIso8601String());
                    Notification::make()->title('Folder details saved. Press "Test the folder".')->success()->send();
                }),
            Action::make('testRelay')
                ->label('Test the folder')
                ->color('gray')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->action(function (): void {
                    $relay = app(RelayFolder::class);
                    if ($relay === null) {
                        Notification::make()->title('Save the folder details first.')->warning()->send();

                        return;
                    }
                    $result = $relay->test();
                    Notification::make()
                        ->title($result['ok'] ? 'The folder works' : 'The folder test failed')
                        ->body($result['ok'] ? null : collect($result['steps'])->where('ok', false)->pluck('note')->implode("\n"))
                        ->status($result['ok'] ? 'success' : 'danger')
                        ->send();
                }),
        ];
    }

    protected function getViewData(): array
    {
        return [
            'host' => Setting::get('relay.host'),
            'port' => Setting::get('relay.port', 21),
            'username' => Setting::get('relay.username'),
            'publicUrl' => Setting::get('relay.public_url'),
            'ftps' => (bool) Setting::get('relay.ftps', true),
            'passwordSaved' => Setting::getSecret('relay.password') !== null,
            'lastTest' => Setting::get('relay.last_test'),
            'zone' => Filament::getTenant()?->timezone ?? (string) config('smm.display_timezone'),
        ];
    }
}
