<?php

namespace Tests\Unit;

use App\Domain\Backup\UsbReminder;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UsbReminderTest extends TestCase
{
    public static function cases(): array
    {
        return [
            'never copied' => [null, true],
            'copied 3 days ago' => ['-3 days', false],
            'copied 7 days ago' => ['-7 days', true],
            'copied 8 days ago' => ['-8 days', true],
        ];
    }

    #[DataProvider('cases')]
    public function test_is_due(?string $copied, bool $expected): void
    {
        $now = CarbonImmutable::parse('2026-11-14 10:00:00', 'UTC');
        $confirmedAt = $copied === null ? null : $now->modify($copied);

        $this->assertSame($expected, UsbReminder::isDue($confirmedAt, $now, 7));
    }
}
