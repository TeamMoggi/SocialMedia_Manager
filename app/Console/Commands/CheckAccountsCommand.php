<?php

namespace App\Console\Commands;

use App\Domain\Accounts\AccountHealth;
use App\Domain\Meta\MetaConnector;
use App\Domain\Meta\MetaCredentials;
use App\Enums\AccountStatus;
use App\Enums\Network;
use App\Models\Brand;
use App\Models\ConnectedAccount;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

class CheckAccountsCommand extends Command
{
    protected $signature = 'smm:check-accounts';

    protected $description = 'Update each connected account\'s status and warn before access expires';

    public function handle(MetaConnector $meta): int
    {
        $this->checkMetaKeys($meta);

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

    /**
     * Asks Meta whether each brand's permanent key still works. A key that stopped working puts the
     * brand's Facebook and Instagram accounts in Error (with one message); a working key records its
     * expiry on them, so the expiry warnings below apply. No connection: nothing changes.
     */
    private function checkMetaKeys(MetaConnector $meta): void
    {
        Brand::with('users')->get()->each(function (Brand $brand) use ($meta): void {
            if (MetaCredentials::key($brand) === null) {
                return;
            }
            $check = $meta->checkKey($brand);
            if ($check->unreachable) {
                $this->line('Meta key: could not check (no connection).');

                return;
            }

            $accounts = $brand->connectedAccounts()->whereIn('network', [Network::FacebookPage, Network::Instagram])->get();
            $problem = $check->problem
                ?? ($check->missing !== [] ? 'The Meta key is missing: '.implode(', ', $check->missing).'. Make a new key and tick these.' : null);

            if ($problem === null) {
                $accounts->each(fn (ConnectedAccount $a) => $a->update(['token_expires_at' => $check->expiresAt]));
                $this->line('Meta key: works.');

                return;
            }

            $problem = str_contains($problem, 'Make a new key') ? $problem : $problem.' Make a new key and save it on the Meta page.';
            $newlyBroken = $accounts->filter(fn (ConnectedAccount $a): bool => $a->status !== AccountStatus::Error);
            $accounts->each(fn (ConnectedAccount $a) => $a->update(['status' => AccountStatus::Error, 'last_error' => $problem]));
            $this->line("Meta key: {$problem}");

            if ($newlyBroken->isNotEmpty()) {
                Notification::make()
                    ->title('Meta key stopped working')
                    ->body("{$problem} Until then nothing can be posted to Facebook or Instagram for {$brand->name}.")
                    ->danger()
                    ->sendToDatabase($brand->users);
            }
        });
    }
}
