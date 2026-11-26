<?php

namespace App\Console\Commands;

use App\Domain\Publishing\Publisher;
use App\Domain\Publishing\WakeTasks;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Sends whatever is due: hands approved posts to Facebook, prepares and publishes Instagram, retries, and catches
 * up the next day. Run by the wake-up tasks, by the open app (every minute) and by smm:daily. Never inside a web
 * request. In Dev it only prints what it would do.
 */
class PublishDueCommand extends Command
{
    protected $signature = 'smm:publish-due {--now= : Pretend it is this time (UTC, for checks)}';

    protected $description = 'Send the posts that are due on Facebook and Instagram';

    public function handle(): int
    {
        $now = $this->option('now') ? CarbonImmutable::parse((string) $this->option('now'), 'UTC') : null;
        $result = app(Publisher::class)->run($now);

        foreach ($result['lines'] as $line) {
            $this->line($line);
        }
        $this->info("Published {$result['done']}, failed {$result['failed']}, late {$result['late']}, missed {$result['missed']}.");

        // Wake-up tasks follow what is waiting now (Windows, App copy only).
        app(WakeTasks::class)->sync();

        return self::SUCCESS;
    }
}
