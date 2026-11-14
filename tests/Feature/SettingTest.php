<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_setting_round_trip(): void
    {
        $this->assertNull(Setting::get('missing'));
        $this->assertSame('fallback', Setting::get('missing', 'fallback'));

        Setting::put('last_backup_file', 'smm-backup-20261114-2100.zip');
        Setting::put('numbers', ['a' => 1, 'b' => [2, 3]]);
        Setting::put('last_backup_file', 'smm-backup-20261115-2100.zip');

        $this->assertSame('smm-backup-20261115-2100.zip', Setting::get('last_backup_file'));
        $this->assertSame(['a' => 1, 'b' => [2, 3]], Setting::get('numbers'));
        $this->assertSame(2, DB::table('settings')->count());
    }

    public function test_secret_is_encrypted_at_rest(): void
    {
        Setting::putSecret('backup_password', 'correct horse battery');

        $raw = DB::table('settings')->where('key', 'backup_password')->value('value');
        $this->assertStringNotContainsString('correct horse battery', $raw);
        $this->assertSame('correct horse battery', Setting::getSecret('backup_password'));
        $this->assertNull(Setting::getSecret('never_set'));
    }
}
