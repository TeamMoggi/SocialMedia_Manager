<?php

namespace App\Console\Commands;

use App\Domain\Relay\RelayFolder;
use Illuminate\Console\Command;
use Throwable;

/**
 * The once-a-day jobs, run by the Windows task "\SocialMediaManager\Daily" at 09:00 (or as soon as the
 * laptop is on after a missed 09:00). Each job runs directly, so a late start still does all of them, and
 * a job that fails does not stop the others (above all the backup and the "daily jobs ran" record).
 */
class DailyCommand extends Command
{
    protected $signature = 'smm:daily';

    protected $description = 'Run the daily jobs: account check, backup, and the "daily jobs ran" record';

    public function handle(): int
    {
        // A Facebook test post that was not deleted by hand goes now (SM-008).
        $this->job('Test post clean-up', fn () => $this->call('smm:meta:test-post', ['--cleanup' => true]));
        // Files left in the Hostinger folder by a crash or an interrupted upload (SM-008).
        $this->job('Hostinger folder clean-up', function (): void {
            $removed = app(RelayFolder::class)?->sweep() ?? 0;
            if ($removed > 0) {
                $this->line("Hostinger folder: removed {$removed} old file(s).");
            }
        });
        $this->job('Account check', fn () => $this->call('smm:check-accounts'));
        // New, changed and missing files in Markting Social Media (read only, SM-009).
        $this->job('Library scan', fn () => $this->call('smm:media:scan'));
        // A backup problem (for example no password yet) is shown on the Backups page; the other jobs go on.
        $this->job('Backup', fn () => $this->call('smm:backup', ['--if-older-than' => 20]));
        $this->job('Daily jobs record', fn () => $this->call('smm:heartbeat'));

        return self::SUCCESS;
    }

    private function job(string $name, callable $run): void
    {
        try {
            $run();
        } catch (Throwable $e) {
            $this->error("{$name} did not run: {$e->getMessage()}");
        }
    }
}
