<?php

namespace Tests\Unit;

use App\Domain\System\SystemHealth;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SystemHealthTest extends TestCase
{
    public static function cases(): array
    {
        return [
            'never ran' => [null, null, true],
            'ran 2 minutes ago' => ['-2 minutes', 2, false],
            'ran 3 minutes ago' => ['-3 minutes', 3, false],
            'ran 5 minutes ago' => ['-5 minutes', 5, true],
        ];
    }

    #[DataProvider('cases')]
    public function test_heartbeat_age_and_staleness(?string $ago, ?int $expectedAge, bool $expectedStale): void
    {
        $now = CarbonImmutable::parse('2026-11-14 10:15:00', 'UTC');
        $health = new SystemHealth($ago === null ? null : $now->modify($ago), 3);

        $this->assertSame($expectedAge, $health->heartbeatAgeMinutes($now));
        $this->assertSame($expectedStale, $health->schedulerIsStale($now));
    }

    public static function wording(): array
    {
        return [
            'never' => [null, 'Daily jobs have not run yet'],
            'seconds ago' => ['-20 seconds', 'Daily jobs ran less than a minute ago'],
            '1 minute' => ['-1 minute', 'Daily jobs ran 1 minute ago'],
            '125 minutes' => ['-125 minutes', 'Daily jobs ran 2 hours ago'],
            '1 hour' => ['-61 minutes', 'Daily jobs ran 1 hour ago'],
            '3 days' => ['-3 days', 'Daily jobs ran 3 days ago'],
        ];
    }

    #[DataProvider('wording')]
    public function test_describe(?string $ago, string $expected): void
    {
        $now = CarbonImmutable::parse('2026-11-14 10:15:00', 'UTC');

        $this->assertSame($expected, (new SystemHealth($ago === null ? null : $now->modify($ago), 1560))->describe($now));
    }
}
