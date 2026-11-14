<?php

namespace App\Console\Commands;

use App\Domain\Backup\BackupMaker;
use App\Domain\Backup\BackupPruner;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class BackupCommand extends Command
{
    protected $signature = 'smm:backup {--if-older-than= : Skip when the last backup is newer than this many hours}';

    protected $description = 'Make a password-protected backup of the data folder and keep the newest 14';

    public function handle(BackupMaker $maker): int
    {
        $now = CarbonImmutable::now('UTC');

        $hours = $this->option('if-older-than');
        $last = Setting::get('last_backup_at');
        if ($hours !== null && $last && CarbonImmutable::parse($last)->greaterThan($now->subHours((int) $hours))) {
            $this->info("Skipped: the last backup ({$last}) is newer than {$hours} hours.");

            return self::SUCCESS;
        }

        $password = Setting::getSecret('backup_password');
        if ($password === null) {
            $message = 'Set a backup password in the app first (Backups page).';
            Setting::put('last_backup_error', $message);
            $this->error($message);

            return self::FAILURE;
        }

        $dir = (string) (config('smm.backup_dir') ?: app('smm.data_dir').DIRECTORY_SEPARATOR.'backups');

        try {
            $path = $maker->make($dir, $password, $now);
            $deleted = BackupPruner::prune($dir, (int) config('smm.backup_keep'));
        } catch (Throwable $e) {
            Setting::put('last_backup_error', $e->getMessage());
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        Setting::put('last_backup_at', $now->toIso8601String());
        Setting::put('last_backup_file', basename($path));
        Setting::put('last_backup_error', null);
        $this->info("Backup saved: {$path}".($deleted ? ' (removed '.count($deleted).' older)' : ''));

        return self::SUCCESS;
    }
}
