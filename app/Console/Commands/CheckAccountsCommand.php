<?php

namespace App\Console\Commands;

use App\Domain\Accounts\AccountHealth;
use App\Enums\AccountStatus;
use App\Models\ConnectedAccount;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

class CheckAccountsCommand extends Command
{
    protected $signature = 'smm:check-accounts';

    protected $description = 'Update each connected account\'s status and warn before access expires';

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $warningDays = (int) config('smm.token_expiry_warning_days');
        $changed = 0;

        ConnectedAccount::with('brand.users')->each(function (ConnectedAccount $account) use ($now, $warningDays, &$changed): void {
            $new = AccountHealth::evaluate($account->token_expires_at, $account->status, $now, $warningDays);
            if ($new === $account->status) {
                return;
            }
            $account->update(['status' => $new]);
            $changed++;

            if (in_array($new, [AccountStatus::Expiring, AccountStatus::Expired], true)) {
                $brand = $account->brand;
                Notification::make()
                    ->title($new === AccountStatus::Expired
                        ? "Reconnect {$account->name}"
                        : "{$account->name}: access expires soon")
                    ->body($new === AccountStatus::Expired
                        ? "Access to this {$account->network->label()} ({$brand->name}) has expired. Posts to it will fail until it is reconnected."
                        : "Access to this {$account->network->label()} ({$brand->name}) expires on {$account->token_expires_at->setTimezone($brand->timezone)->format('j M Y')}. Reconnect it before then.")
                    ->status($new === AccountStatus::Expired ? 'danger' : 'warning')
                    ->sendToDatabase($brand->users);
            }
        });

        $this->info("Accounts checked; {$changed} changed.");

        return self::SUCCESS;
    }
}
