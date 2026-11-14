<?php

namespace App\Domain\System;

use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * Are the timed jobs (Windows Task Scheduler → schedule:run every minute) running?
 */
final class SystemHealth
{
    public function __construct(
        private readonly ?CarbonImmutable $lastHeartbeat,
        private readonly int $staleMinutes,
    ) {}

    public static function current(): self
    {
        $at = Setting::get('heartbeat_at');

        return new self($at ? CarbonImmutable::parse($at) : null, (int) config('smm.heartbeat_stale_minutes'));
    }

    public function heartbeatAgeMinutes(CarbonImmutable $now): ?int
    {
        return $this->lastHeartbeat === null
            ? null
            : (int) floor(max(0, $now->getTimestamp() - $this->lastHeartbeat->getTimestamp()) / 60);
    }

    public function schedulerIsStale(CarbonImmutable $now): bool
    {
        $age = $this->heartbeatAgeMinutes($now);

        return $age === null || $age > $this->staleMinutes;
    }

    public function describe(CarbonImmutable $now): string
    {
        $age = $this->heartbeatAgeMinutes($now);
        if ($age === null) {
            return 'Timed jobs have not run yet';
        }
        $when = $age === 0 ? 'less than a minute ago' : ($age === 1 ? '1 minute ago' : "{$age} minutes ago");

        return "Timed jobs ran {$when}";
    }
}
