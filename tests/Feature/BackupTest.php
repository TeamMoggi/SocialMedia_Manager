<?php

namespace Tests\Feature;

use App\Domain\Backup\BackupMaker;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use PDO;
use Tests\TestCase;
use ZipArchive;

// DatabaseMigrations, not RefreshDatabase: SQLite cannot VACUUM INTO inside the test's transaction.
class BackupTest extends TestCase
{
    use DatabaseMigrations;

    private string $target;

    private string $mediaFile;

    private string $envFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->target = sys_get_temp_dir().DIRECTORY_SEPARATOR.'smm-backups-'.bin2hex(random_bytes(4));
        config(['smm.backup_dir' => $this->target]);

        $data = app('smm.data_dir');
        @mkdir($data.DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.'test', 0777, true);
        $this->mediaFile = $data.DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.'test'.DIRECTORY_SEPARATOR.'raisins.jpg';
        file_put_contents($this->mediaFile, 'JPEG-BYTES-raisins');
        $this->envFile = $data.DIRECTORY_SEPARATOR.'.env';
        file_put_contents($this->envFile, "APP_NAME=\"SocialMedia Manager\"\nSMM_PUBLISH_MODE=dry-run\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->mediaFile);
        @rmdir(dirname($this->mediaFile));
        @unlink($this->envFile);
        foreach (glob($this->target.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->target);
        parent::tearDown();
    }

    public function test_backup_zip_holds_database_env_and_media_encrypted(): void
    {
        $now = CarbonImmutable::parse('2026-11-14 15:30:00', 'UTC'); // 21:00 in India

        $path = app(BackupMaker::class)->make($this->target, 'correct horse battery', $now);

        $this->assertSame($this->target.DIRECTORY_SEPARATOR.'smm-backup-20261114-2100.zip', $path);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        sort($names);
        $this->assertSame(['.env', 'database.sqlite', 'media/test/raisins.jpg'], $names);
        $this->assertFalse(@$zip->getFromName('media/test/raisins.jpg'));
        $zip->setPassword('correct horse battery');
        $this->assertSame('JPEG-BYTES-raisins', $zip->getFromName('media/test/raisins.jpg'));
        $zip->close();
    }

    public function test_backup_database_passes_integrity_check(): void
    {
        $path = app(BackupMaker::class)->make($this->target, 'correct horse battery', CarbonImmutable::now());

        $zip = new ZipArchive;
        $zip->open($path);
        $zip->setPassword('correct horse battery');
        $copy = $this->target.DIRECTORY_SEPARATOR.'check.sqlite';
        file_put_contents($copy, $zip->getFromName('database.sqlite'));
        $zip->close();

        $pdo = new PDO('sqlite:'.$copy);
        $this->assertSame('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertSame('brands', $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='brands'")->fetchColumn());
        $pdo = null;
    }

    public function test_backup_fails_clearly_without_password(): void
    {
        $this->artisan('smm:backup')
            ->expectsOutputToContain('Set a backup password in the app first')
            ->assertExitCode(1);

        $this->assertNotNull(Setting::get('last_backup_error'));
        $this->assertSame([], glob($this->target.DIRECTORY_SEPARATOR.'*.zip') ?: []);
    }

    public function test_if_older_than_skips_recent_backup(): void
    {
        Setting::putSecret('backup_password', 'correct horse battery');
        Setting::put('last_backup_at', CarbonImmutable::now('UTC')->subHours(2)->toIso8601String());

        $this->artisan('smm:backup', ['--if-older-than' => 20])
            ->expectsOutputToContain('Skipped')
            ->assertExitCode(0);

        $this->assertSame([], glob($this->target.DIRECTORY_SEPARATOR.'*.zip') ?: []);
    }

    public function test_backup_command_records_the_backup(): void
    {
        Setting::putSecret('backup_password', 'correct horse battery');
        Setting::put('last_backup_error', 'old problem');

        $this->artisan('smm:backup', ['--if-older-than' => 20])->assertExitCode(0);

        $files = glob($this->target.DIRECTORY_SEPARATOR.'smm-backup-*.zip');
        $this->assertCount(1, $files);
        $this->assertSame(basename($files[0]), Setting::get('last_backup_file'));
        $this->assertNotNull(Setting::get('last_backup_at'));
        $this->assertNull(Setting::get('last_backup_error'));
    }
}
