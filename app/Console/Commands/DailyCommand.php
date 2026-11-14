<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * The once-a-day jobs, run by the Windows task "\SocialMediaManager\Daily" at 09:00 (or as soon as the
 * laptop is on after a missed 09:00). Each job runs directly, so a late start still does all of them.
 */
class DailyCommand extends Command
{
    protected $signature = 'smm:daily';

    protected $description = 'Run the daily jobs: account check, backup, and the "daily jobs ran" record';

    public function handle(): int
    {
        $this->call('smm:check-accounts');
        // A backup problem (for example no password yet) is shown on the Backups page; the other jobs go on.
        $this->call('smm:backup', ['--if-older-than' => 20]);
        $this->call('smm:heartbeat');

        return self::SUCCESS;
    }
}
