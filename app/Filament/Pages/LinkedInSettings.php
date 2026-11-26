<?php

namespace App\Filament\Pages;

use App\Domain\LinkedIn\LinkedInConnector;
use App\Domain\LinkedIn\LinkedInCredentials;
use App\Domain\LinkedIn\LinkedInError;
use App\Domain\LinkedIn\SignInResult;
use App\Enums\Network;
use App\Http\Controllers\LinkedInAuthController;
use App\Models\Brand;
use App\Models\ConnectedAccount;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * LinkedIn company page: the owner saves the LinkedIn app's Client ID and Client Secret, then presses
 * Connect LinkedIn and approves on LinkedIn (every 60 days). Secrets and the key are never shown.
 */
class LinkedInSettings extends Page
{
    protected string $view = 'filament.pages.linkedin-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static ?string $navigationLabel = 'LinkedIn';

    protected static ?string $title = 'LinkedIn';

    protected static ?string $slug = 'linkedin';

    protected static ?int $navigationSort = 81;

    public function mount(): void
    {
        $result = SignInResult::take($this->brand());
        if ($result !== null) {
            Notification::make()
                ->title($result['title'])
                ->body($result['body'])
                ->status($result['ok'] ? 'success' : (str_starts_with($result['title'], 'Connected') ? 'warning' : 'danger'))
                ->persistent()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('connect')
                ->label(fn (): string => $this->account() ? 'Reconnect LinkedIn' : 'Connect LinkedIn')
                ->icon(Heroicon::OutlinedLink)
                ->visible(fn (): bool => LinkedInCredentials::clientSecret() !== null)
                // The whole sign-in runs on localhost, the address saved in the LinkedIn developer portal.
                ->url(fn (): string => LinkedInAuthController::startUrl($this->brand(), (int) config('smm.port'))),
            Action::make('saveApp')
                ->label(fn (): string => LinkedInCredentials::clientSecret() ? 'Change app details' : 'Save app details')
                ->color('gray')
                ->icon(Heroicon::OutlinedLockClosed)
                ->modalHeading('LinkedIn app')
                ->modalDescription('From linkedin.com/developers → My apps → Moggies Social Manager → Auth. The Client Secret is stored locked on this laptop and never shown again. Never paste it into a chat.')
                ->schema([
                    TextInput::make('client_id')
                        ->label('Client ID')
                        ->default(fn (): string => LinkedInCredentials::clientId())
                        ->required(),
                    TextInput::make('client_secret')
                        ->label('Client Secret')
                        ->password()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    LinkedInCredentials::saveApp($data['client_id'], $data['client_secret']);
                    Notification::make()->title('LinkedIn app details saved')->success()->send();
                }),
            Action::make('checkNow')
                ->label('Check the key now')
                ->color('gray')
                ->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn (): bool => $this->account() !== null)
                ->action(function (): void {
                    try {
                        // The same check and record as the daily job, so the page and the account agree.
                        $problem = app(LinkedInConnector::class)->checkAndRecord($this->brand())['problem'];
                    } catch (LinkedInError $e) {
                        Notification::make()->title('Could not check now')->body($e->plain())->warning()->send();

                        return;
                    }
                    $problem === null
                        ? Notification::make()->title('LinkedIn key works')->success()->send()
                        : Notification::make()->title('LinkedIn key problem')->body($problem)->danger()->persistent()->send();
                }),
        ];
    }

    private function brand(): Brand
    {
        /** @var Brand $brand */
        $brand = Filament::getTenant();

        return $brand;
    }

    private function account(): ?ConnectedAccount
    {
        return $this->brand()->connectedAccounts()->where('network', Network::LinkedInOrganization)->first();
    }

    protected function getViewData(): array
    {
        return [
            'clientId' => LinkedInCredentials::clientId(),
            'secretSavedAt' => LinkedInCredentials::savedAt(),
            'hasSecret' => LinkedInCredentials::clientSecret() !== null,
            'account' => $this->account(),
            'orgId' => LinkedInCredentials::orgId($this->brand()),
            'zone' => $this->brand()->timezone ?? (string) config('smm.display_timezone'),
        ];
    }
}
