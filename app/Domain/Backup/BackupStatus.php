<?php

namespace App\Domain\Backup;

use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * What the Backups page and the dashboard card say about backups.
 */
final class BackupStatus
{
    public function __construct(
        public readonly bool $passwordSet,
        public readonly ?CarbonImmutable $lastBackupAt,
        public readonly ?string $lastBackupFile,
        public readonly ?string $lastError,
        public readonly bool $usbDue,
        public readonly string $folder,
    ) {}

    public static function current(CarbonImmutable $now): self
    {
        $last = Setting::get('last_backup_at');
        $usb = Setting::get('usb_copy_confirmed_at');

        return new self(
            passwordSet: Setting::getSecret('backup_password') !== null,
            lastBackupAt: $last ? CarbonImmutable::parse($last) : null,
            lastBackupFile: Setting::get('last_backup_file'),
            lastError: Setting::get('last_backup_error'),
            usbDue: UsbReminder::isDue($usb ? CarbonImmutable::parse($usb) : null, $now, (int) config('smm.usb_reminder_days')),
            folder: (string) (config('smm.backup_dir') ?: app('smm.data_dir').DIRECTORY_SEPARATOR.'backups'),
        );
    }

    public function backupIsOverdue(CarbonImmutable $now): bool
    {
        return $this->lastBackupAt === null || $this->lastBackupAt->lessThan($now->subHours(26));
    }

    /**
     * @return array<int, string> plain-language warnings, most important first
     */
    public function warnings(CarbonImmutable $now): array
    {
        $warnings = [];
        if (! $this->passwordSet) {
            $warnings[] = 'No backup password yet: open Backups and set one. Until then no backups are made.';
        } elseif ($this->lastError) {
            $warnings[] = 'The last backup failed: '.$this->lastError;
        } elseif ($this->backupIsOverdue($now)) {
            $warnings[] = 'No backup in the last 26 hours (the laptop may have been off).';
        }
        if ($this->usbDue) {
            $warnings[] = 'Copy the Backups folder to your USB drive (not done in the last '.config('smm.usb_reminder_days').' days).';
        }

        return $warnings;
    }
}
