<?php

namespace App\Filament\Pages;

use App\Domain\Meta\MetaConnector;
use App\Domain\Meta\MetaCredentials;
use App\Enums\Network;
use App\Models\Brand;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Meta (Facebook and Instagram): the owner types the App Secret and the permanent key here; the app
 * then connects the Page and its Instagram account. Secrets are never shown back.
 */
class MetaSettings extends Page
{
    protected string $view = 'filament.pages.meta-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $navigationLabel = 'Meta (Facebook & Instagram)';

    protected static ?string $title = 'Meta (Facebook & Instagram)';

    protected static ?string $slug = 'meta';

    protected static ?int $navigationSort = 80;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('saveKey')
                ->label(fn (): string => MetaCredentials::key($this->brand()) ? 'Change Meta key' : 'Save Meta key')
                ->icon(Heroicon::OutlinedKey)
                ->modalHeading('Permanent Meta key')
                ->modalDescription('Paste the key you made in Meta\'s business settings (System users → Moggies SMM App → Generate token). It is stored locked on this laptop and never shown again. Never paste it into a chat.')
                ->schema([
                    TextInput::make('key')
                        ->label('Permanent key')
                        ->password()
                        ->required()
                        ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            if (MetaCredentials::appSecret() === null) {
                                $fail('Save the App Secret first.');
                            }
                        }]),
                ])
                ->action(function (array $data): void {
                    MetaCredentials::saveKey($this->brand(), $data['key']);
                    $this->connectAndReport();
                }),
            Action::make('saveAppSecret')
                ->label(fn (): string => MetaCredentials::appSecret() ? 'Change App Secret' : 'Save App Secret')
                ->color('gray')
                ->icon(Heroicon::OutlinedLockClosed)
                ->modalHeading('Meta app')
                ->modalDescription('From developers.facebook.com → Moggies Social Manager → App settings → Basic. The App Secret is stored locked on this laptop and never shown again.')
                ->schema([
                    TextInput::make('app_id')
                        ->label('App ID')
                        ->default(fn (): string => MetaCredentials::appId())
                        ->required()
                        ->regex('/^\s*\d+\s*$/'),
                    TextInput::make('app_secret')
                        ->label('App Secret')
                        ->password()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    MetaCredentials::saveAppSecret($data['app_id'], $data['app_secret']);
                    Notification::make()->title('App Secret saved')->success()->send();
                }),
            Action::make('checkNow')
                ->label('Check the key now')
                ->color('gray')
                ->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn (): bool => MetaCredentials::key($this->brand()) !== null)
                ->action(fn () => $this->connectAndReport()),
        ];
    }

    private function connectAndReport(): void
    {
        $result = app(MetaConnector::class)->connect($this->brand());

        if ($result['problems'] !== []) {
            Notification::make()->title('Not connected')->body(implode("\n", $result['problems']))->danger()->persistent()->send();

            return;
        }

        $names = array_filter([$result['page']?->name, $result['instagram']?->name]);
        Notification::make()
            ->title('Connected: '.implode(' and ', $names))
            ->body($result['warnings'] !== [] ? implode("\n", $result['warnings']) : null)
            ->status($result['warnings'] !== [] ? 'warning' : 'success')
            ->send();
    }

    private function brand(): Brand
    {
        /** @var Brand $brand */
        $brand = Filament::getTenant();

        return $brand;
    }

    protected function getViewData(): array
    {
        $brand = $this->brand();
        $accounts = $brand->connectedAccounts()->whereIn('network', [Network::FacebookPage, Network::Instagram])->get();

        return [
            'appId' => MetaCredentials::appId(),
            'secretSavedAt' => MetaCredentials::savedAt('app_secret'),
            'keySavedAt' => MetaCredentials::savedAt('key', $brand),
            'check' => MetaConnector::lastCheck($brand),
            'accounts' => $accounts,
            'zone' => $brand->timezone ?? (string) config('smm.display_timezone'),
        ];
    }
}
