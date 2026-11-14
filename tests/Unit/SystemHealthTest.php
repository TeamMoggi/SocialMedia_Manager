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
}
