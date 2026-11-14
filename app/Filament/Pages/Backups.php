<?php

namespace App\Filament\Pages;

use App\Domain\Backup\BackupStatus;
use App\Models\Setting;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;

class Backups extends Page
{
    protected string $view = 'filament.pages.backups';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $navigationLabel = 'Backups';

    protected static ?string $title = 'Backups';

    protected static ?int $navigationSort = 90;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backupNow')
                ->label('Back up now')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(function (): void {
                    $ok = Artisan::call('smm:backup') === 0;
                    Notification::make()
                        ->title($ok ? 'Backup saved' : 'Backup not made')
                        ->body($ok ? Setting::get('last_backup_file') : Setting::get('last_backup_error'))
                        ->status($ok ? 'success' : 'danger')
                        ->send();
                }),
            Action::make('setPassword')
                ->label(fn (): string => Setting::getSecret('backup_password') ? 'Change backup password' : 'Set backup password')
                ->color('gray')
                ->icon(Heroicon::OutlinedKey)
                ->modalHeading('Backup password')
                ->modalDescription('Every backup is locked with this password. Keep it somewhere safe outside this laptop: without it nobody can open the backups, not even Claude. Changing it affects new backups only.')
                ->schema([
                    TextInput::make('password')
                        ->label('Backup password (at least 12 characters)')
                        ->password()
                        ->revealable()
                        ->required()
                        ->minLength(12)
                        ->same('password_confirmation'),
                    TextInput::make('password_confirmation')
                        ->label('Type it again')
                        ->password()
                        ->revealable()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    Setting::putSecret('backup_password', $data['password']);
                    Notification::make()->title('Backup password saved')->success()->send();
                }),
            Action::make('usbCopied')
                ->label('I copied Backups to my USB drive today')
                ->color('gray')
                ->icon(Heroicon::OutlinedCheck)
                ->action(function (): void {
                    Setting::put('usb_copy_confirmed_at', CarbonImmutable::now('UTC')->toIso8601String());
                    Notification::make()->title('Thanks. The next reminder comes in '.config('smm.usb_reminder_days').' days.')->success()->send();
                }),
        ];
    }

    protected function getViewData(): array
    {
        $now = CarbonImmutable::now();
        $zone = Filament::getTenant()?->timezone ?? (string) config('smm.display_timezone');

        return [
            'status' => BackupStatus::current($now),
            'warnings' => BackupStatus::current($now)->warnings($now),
            'zone' => $zone,
        ];
    }
}
