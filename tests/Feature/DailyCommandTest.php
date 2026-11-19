<?php

namespace Tests\Feature;

use App\Domain\Meta\MetaGraph;
use App\Enums\AccountStatus;
use App\Enums\Network;
use App\Models\Brand;
use App\Models\ConnectedAccount;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyCommandTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_daily_runs_the_other_jobs_when_one_fails(): void
    {
        // Something unexpected in the Meta part must not stop the backup and the "daily jobs ran" record.
        app()->bind(MetaGraph::class, fn () => throw new \RuntimeException('Unexpected Meta trouble'));

        $this->artisan('smm:daily')
            ->expectsOutputToContain('Unexpected Meta trouble')
            ->expectsOutputToContain('Set a backup password in the app first')
            ->assertExitCode(0);

        $this->assertNotNull(Setting::get('heartbeat_at'));
        $this->assertNotNull(Setting::get('last_backup_error'));
    }

    public function test_daily_checks_accounts_and_records_heartbeat_even_without_backup_password(): void
    {
        // Run at 11:23 (after a missed 09:00): the jobs must run anyway, not only at their exact minute.
        $this->travelTo(CarbonImmutable::parse('2026-11-14 05:53:00', 'UTC'));
        $account = ConnectedAccount::create([
            'brand_id' => Brand::firstOrFail()->id,
            'network' => Network::Instagram,
            'external_id' => 'moggies_international',
            'name' => 'moggies_international',
            'access_token' => 'token',
            'token_expires_at' => now()->addDays(2),
        ]);

        $this->artisan('smm:daily')
            ->expectsOutputToContain('Set a backup password in the app first')
            ->assertExitCode(0);

        $this->assertSame(AccountStatus::Expiring, $account->fresh()->status);
        $this->assertSame('2026-11-14T05:53:00+00:00', Setting::get('heartbeat_at'));
        $this->assertNotNull(Setting::get('last_backup_error'));
    }
}
