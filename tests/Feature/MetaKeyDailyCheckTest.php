<?php

namespace Tests\Feature;

use App\Domain\Meta\MetaCredentials;
use App\Enums\AccountStatus;
use App\Enums\Network;
use App\Models\Brand;
use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaKeyDailyCheckTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->brand = Brand::where('slug', 'moggies-international')->firstOrFail();
    }

    private function connectedMeta(): void
    {
        MetaCredentials::saveAppSecret('2181731786035384', 'app-secret-value');
        MetaCredentials::saveKey($this->brand, 'EAAkey123');
        foreach ([[Network::FacebookPage, '1510742765451658', 'Moggies International'], [Network::Instagram, '17841470732347612', '@moggies_international']] as [$network, $id, $name]) {
            ConnectedAccount::create(['brand_id' => $this->brand->id, 'network' => $network, 'external_id' => $id, 'name' => $name, 'access_token' => 'page-tok']);
        }
    }

    private function owner(): User
    {
        return User::where('email', User::LOCAL_OWNER_EMAIL)->firstOrFail();
    }

    public function test_invalid_key_marks_meta_accounts_error_and_notifies_once(): void
    {
        $this->connectedMeta();
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => ['app_id' => '2181731786035384', 'is_valid' => false]])]);

        $this->artisan('smm:check-accounts')->assertExitCode(0);
        $this->artisan('smm:check-accounts')->assertExitCode(0);

        foreach (ConnectedAccount::all() as $account) {
            $this->assertSame(AccountStatus::Error, $account->status);
            $this->assertStringContainsString('Make a new key', $account->last_error);
        }
        $this->assertSame(1, $this->owner()->notifications()->count());
        $this->assertStringContainsString('Meta key stopped working', json_encode($this->owner()->notifications()->first()->data));
    }

    public function test_wrong_app_secret_is_not_blamed_on_the_key(): void
    {
        $this->connectedMeta();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token signature.', 'code' => 190]], 400)]);

        $this->artisan('smm:check-accounts')->assertExitCode(0);

        $error = ConnectedAccount::firstOrFail()->last_error;
        $this->assertStringContainsString('App Secret', $error);
        $this->assertStringNotContainsString('Make a new key', $error);
    }

    public function test_offline_check_changes_nothing(): void
    {
        $this->connectedMeta();
        Http::fake(fn () => throw new ConnectionException('Could not resolve host'));

        $this->artisan('smm:check-accounts')
            ->expectsOutputToContain('Meta key: could not check now')
            ->assertExitCode(0);

        foreach (ConnectedAccount::all() as $account) {
            $this->assertSame(AccountStatus::Connected, $account->status);
        }
        $this->assertSame(0, $this->owner()->notifications()->count());
    }

    public function test_meta_temporary_error_changes_nothing(): void
    {
        $this->connectedMeta();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Service temporarily unavailable', 'code' => 2, 'is_transient' => true]], 500)]);

        $this->artisan('smm:check-accounts')
            ->expectsOutputToContain('Meta key: could not check')
            ->assertExitCode(0);

        foreach (ConnectedAccount::all() as $account) {
            $this->assertSame(AccountStatus::Connected, $account->status);
        }
        $this->assertSame(0, $this->owner()->notifications()->count());
    }

    public function test_accounts_recover_when_the_key_works_again(): void
    {
        $this->connectedMeta();
        Http::fakeSequence('graph.facebook.com/*')
            ->push(['data' => ['app_id' => '2181731786035384', 'is_valid' => false]])
            ->push(['data' => ['app_id' => '2181731786035384', 'is_valid' => true, 'expires_at' => 0,
                'scopes' => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'instagram_basic', 'instagram_content_publish']]]);

        $this->artisan('smm:check-accounts')->assertExitCode(0);
        $this->assertSame(AccountStatus::Error, ConnectedAccount::firstOrFail()->status);

        $this->artisan('smm:check-accounts')->assertExitCode(0);
        foreach (ConnectedAccount::all() as $account) {
            $this->assertSame(AccountStatus::Connected, $account->status);
            $this->assertNull($account->last_error);
        }
    }

    public function test_error_from_another_cause_is_not_cleared_by_the_key_check(): void
    {
        $this->connectedMeta();
        ConnectedAccount::where('network', Network::Instagram)->update(['status' => AccountStatus::Error, 'last_error' => 'Instagram refused a post.']);
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => ['app_id' => '2181731786035384', 'is_valid' => true, 'expires_at' => 0,
            'scopes' => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'instagram_basic', 'instagram_content_publish']]])]);

        $this->artisan('smm:check-accounts')->assertExitCode(0);

        $this->assertSame(AccountStatus::Error, ConnectedAccount::where('network', Network::Instagram)->sole()->status);
    }

    public function test_valid_key_records_its_expiry_on_the_accounts(): void
    {
        $this->connectedMeta();
        $expires = now()->addDays(30)->startOfSecond();
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [
            'app_id' => '2181731786035384', 'is_valid' => true, 'expires_at' => $expires->getTimestamp(),
            'scopes' => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'instagram_basic', 'instagram_content_publish'],
        ]])]);

        $this->artisan('smm:check-accounts')->assertExitCode(0);

        foreach (ConnectedAccount::all() as $account) {
            $this->assertSame(AccountStatus::Connected, $account->status);
            $this->assertSame($expires->getTimestamp(), $account->token_expires_at->getTimestamp());
        }
    }

    public function test_no_key_means_no_meta_call(): void
    {
        Http::fake();

        $this->artisan('smm:check-accounts')->assertExitCode(0);

        Http::assertNothingSent();
    }
}
