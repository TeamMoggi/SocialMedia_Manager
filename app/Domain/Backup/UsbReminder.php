<?php

namespace App\Domain\Backup;

use Carbon\CarbonImmutable;

/**
 * The owner copies the Backups folder to a USB drive by hand. The app reminds when that has not been
 * confirmed for $days days (or ever).
 */
final class UsbReminder
{
    public static function isDue(?CarbonImmutable $confirmedAt, CarbonImmutable $now, int $days): bool
    {
        return $confirmedAt === null || $confirmedAt->addDays($days)->lessThanOrEqualTo($now);
    }
}
