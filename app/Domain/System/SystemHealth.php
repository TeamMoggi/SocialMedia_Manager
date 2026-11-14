<?php

namespace App\Domain\System;

use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * Are the daily jobs (Windows task "\SocialMediaManager\Daily" → smm:daily) running?
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
            return 'Daily jobs have not run yet';
        }

        return 'Daily jobs ran '.self::ago($age);
    }

    private static function ago(int $minutes): string
    {
        $plural = static fn (int $n, string $unit): string => $n === 1 ? "1 {$unit} ago" : "{$n} {$unit}s ago";

        return match (true) {
            $minutes < 1 => 'less than a minute ago',
            $minutes < 60 => $plural($minutes, 'minute'),
            $minutes < 48 * 60 => $plural(intdiv($minutes, 60), 'hour'),
            default => $plural(intdiv($minutes, 24 * 60), 'day'),
        };
    }
}
