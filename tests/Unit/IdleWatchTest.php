<?php

namespace Tests\Unit;

use App\Domain\System\IdleWatch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IdleWatchTest extends TestCase
{
    public static function cases(): array
    {
        return [
            'used 29 minutes ago' => [29, false],
            'used exactly 30 minutes ago' => [30, true],
            'used 45 minutes ago' => [45, true],
            'used just now' => [0, false],
        ];
    }

    #[DataProvider('cases')]
    public function test_should_stop(int $minutesAgo, bool $expected): void
    {
        $now = 1_794_650_000;

        $this->assertSame($expected, IdleWatch::shouldStop($now - $minutesAgo * 60, $now, 30));
    }
}
