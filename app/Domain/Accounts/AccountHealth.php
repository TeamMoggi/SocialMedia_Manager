<?php

namespace App\Domain\Accounts;

use App\Enums\AccountStatus;
use Carbon\CarbonImmutable;

final class AccountHealth
{
    /**
     * An account in Error stays in Error until it is reconnected; otherwise the status follows the
     * access expiry date: none or far away → Connected, within $warningDays → Expiring, passed → Expired.
     */
    public static function evaluate(?CarbonImmutable $expiresAt, AccountStatus $current, CarbonImmutable $now, int $warningDays): AccountStatus
    {
        if ($current === AccountStatus::Error) {
            return AccountStatus::Error;
        }
        if ($expiresAt === null) {
            return AccountStatus::Connected;
        }
        if ($expiresAt->lessThanOrEqualTo($now)) {
            return AccountStatus::Expired;
        }
        if ($expiresAt->lessThanOrEqualTo($now->addDays($warningDays))) {
            return AccountStatus::Expiring;
        }

        return AccountStatus::Connected;
    }
}
