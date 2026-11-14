<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class HeartbeatCommand extends Command
{
    protected $signature = 'smm:heartbeat';

    protected $description = 'Record that the timed jobs ran (shown on the status card and by smm:doctor)';

    public function handle(): int
    {
        Setting::put('heartbeat_at', CarbonImmutable::now('UTC')->toIso8601String());

        return self::SUCCESS;
    }
}
