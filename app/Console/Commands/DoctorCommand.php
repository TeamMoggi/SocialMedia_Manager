<?php

namespace App\Console\Commands;

use App\Support\PublishGuard;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Throwable;
use ZipArchive;

class DoctorCommand extends Command
{
    protected $signature = 'smm:doctor';

    protected $description = 'Check that this copy of SocialMedia Manager is set up correctly';

    /** @var array<int, array{0: string, 1: string}> */
    private array $results = [];

    public function handle(Migrator $migrator): int
    {
        $this->checkPhp();
        $this->checkExtensions();
        $this->checkDataFolder();
        $this->checkAppKey();
        $this->checkDatabase($migrator);
        $this->checkZipEncryption();
        $this->checkPublishMode();

        foreach ($this->results as [$level, $message]) {
            $this->line(str_pad($level, 6).$message);
        }

        return collect($this->results)->contains(fn ($r) => $r[0] === 'FAIL') ? self::FAILURE : self::SUCCESS;
    }

    private function add(string $level, string $message): void
    {
        $this->results[] = [$level, $message];
    }

    private function checkPhp(): void
    {
        $ok = version_compare(PHP_VERSION, '8.3.0', '>=');
        $this->add($ok ? 'OK' : 'FAIL', 'PHP '.PHP_VERSION.($ok ? '' : ' (Laravel 13 needs 8.3 or newer)'));
    }

    private function checkExtensions(): void
    {
        $missing = array_values(array_filter(config('smm.required_extensions'), fn ($e) => ! extension_loaded($e)));
        $missing
            ? $this->add('FAIL', 'PHP extensions missing: '.implode(', ', $missing).' (switch them on in php.ini)')
            : $this->add('OK', 'PHP extensions: all '.count(config('smm.required_extensions')).' loaded');
    }

    private function checkDataFolder(): void
    {
        $dir = app('smm.data_dir');
        is_dir($dir) && is_writable($dir)
            ? $this->add('OK', "Data folder: {$dir}")
            : $this->add('FAIL', "Data folder: {$dir} is missing or read-only");
    }

    private function checkAppKey(): void
    {
        filled(config('app.key'))
            ? $this->add('OK', 'App key is set')
            : $this->add('FAIL', 'App key is missing (run setup.ps1 again)');
    }

    private function checkDatabase(Migrator $migrator): void
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $this->add('FAIL', 'Database cannot be opened: '.$e->getMessage());

            return;
        }

        $files = array_keys($migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')])));
        $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];
        $pending = array_diff($files, $ran);

        $pending
            ? $this->add('FAIL', count($pending).' database update(s) waiting: run php artisan migrate')
            : $this->add('OK', 'Database: up to date');
    }

    private function checkZipEncryption(): void
    {
        class_exists(ZipArchive::class) && ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, true)
            ? $this->add('OK', 'Encrypted backups: supported')
            : $this->add('FAIL', 'Encrypted backups: this PHP cannot make AES-256 zip files');
    }

    private function checkPublishMode(): void
    {
        $configured = (string) config('smm.publish_mode');
        if ($configured === 'live' && ! PublishGuard::allowsLive(base_path())) {
            $this->add('FAIL', 'Publish mode: Live posting is only allowed in the App folder (this copy is '.basename(base_path()).')');

            return;
        }
        $this->add('OK', 'Publish mode: '.PublishGuard::effectiveMode($configured, base_path()));
    }
}
