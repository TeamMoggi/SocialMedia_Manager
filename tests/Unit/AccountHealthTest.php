<?php

namespace Tests\Unit;

use App\Domain\Accounts\AccountHealth;
use App\Enums\AccountStatus;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AccountHealthTest extends TestCase
{
    public static function cases(): array
    {
        return [
            'no expiry date' => [null, AccountStatus::Connected, AccountStatus::Connected],
            'expired yesterday' => ['-1 day', AccountStatus::Connected, AccountStatus::Expired],
            'expires in 3 days' => ['+3 days', AccountStatus::Connected, AccountStatus::Expiring],
            'expires in 30 days' => ['+30 days', AccountStatus::Connected, AccountStatus::Connected],
            'error stays error' => ['+30 days', AccountStatus::Error, AccountStatus::Error],
            'expiring again fine after reconnect' => ['+60 days', AccountStatus::Expiring, AccountStatus::Connected],
        ];
    }

    #[DataProvider('cases')]
    public function test_evaluate(?string $expiresIn, AccountStatus $current, AccountStatus $expected): void
    {
        $now = CarbonImmutable::parse('2026-11-14 09:00:00', 'UTC');
        $expiresAt = $expiresIn === null ? null : $now->modify($expiresIn);

        $this->assertSame($expected, AccountHealth::evaluate($expiresAt, $current, $now, 7));
    }
}
