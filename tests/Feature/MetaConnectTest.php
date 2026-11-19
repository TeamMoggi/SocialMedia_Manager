<?php

namespace Tests\Feature;

use App\Domain\Meta\MetaConnector;
use App\Domain\Meta\MetaCredentials;
use App\Enums\AccountStatus;
use App\Enums\Network;
use App\Filament\Pages\MetaSettings;
use App\Models\Brand;
use App\Models\ConnectedAccount;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class MetaConnectTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::where('email', User::LOCAL_OWNER_EMAIL)->firstOrFail());
        Filament::setCurrentPanel('app');
        $this->brand = Brand::where('slug', 'moggies-international')->firstOrFail();
        Filament::setTenant($this->brand);
    }

    private function fakeMeta(?array $scopes = null, array $debug = [], ?array $pages = null): void
    {
        $scopes ??= [...MetaConnector::REQUIRED, ...MetaConnector::WANTED];
        $pages ??= [[
            'id' => '1510742765451658',
            'name' => 'Moggies International',
            'access_token' => 'page-tok',
            'instagram_business_account' => ['id' => '17841470732347612', 'username' => 'moggies_international'],
        ]];
        Http::fake([
            'graph.facebook.com/v27.0/debug_token*' => Http::response(['data' => array_merge(
                ['app_id' => '2181731786035384', 'is_valid' => true, 'expires_at' => 0, 'scopes' => $scopes],
                $debug,
            )]),
            'graph.facebook.com/v27.0/me/accounts*' => Http::response(['data' => $pages]),
        ]);
    }

    private function saveCredentials(): void
    {
        MetaCredentials::saveAppSecret('2181731786035384', 'app-secret-value');
        MetaCredentials::saveKey($this->brand, 'EAAkey123');
    }

    private function connect(): array
    {
        return app(MetaConnector::class)->connect($this->brand);
    }

    public function test_saving_the_key_connects_page_and_instagram(): void
    {
        $this->fakeMeta();

        Livewire::test(MetaSettings::class)
            ->callAction('saveAppSecret', data: ['app_id' => '2181731786035384', 'app_secret' => ' app-secret-value '])
            ->assertHasNoActionErrors()
            ->callAction('saveKey', data: ['key' => "  EAAkey\n123 "])
            ->assertHasNoActionErrors();

        $page = ConnectedAccount::where('network', Network::FacebookPage)->sole();
        $this->assertSame('1510742765451658', $page->external_id);
        $this->assertSame('Moggies International', $page->name);
        $this->assertSame('page-tok', $page->access_token);
        $this->assertNull($page->token_expires_at);

        $ig = ConnectedAccount::where('network', Network::Instagram)->sole();
        $this->assertSame('17841470732347612', $ig->external_id);
        $this->assertSame('@moggies_international', $ig->name);
        $this->assertSame('page-tok', $ig->access_token);
        $this->assertSame('1510742765451658', $ig->meta['page_id']);

        $this->assertSame('EAAkey123', MetaCredentials::key($this->brand));
        $this->assertSame('app-secret-value', MetaCredentials::appSecret());
        $raw = DB::table('settings')->pluck('value')->implode("\n");
        $this->assertStringNotContainsString('EAAkey123', $raw);
        $this->assertStringNotContainsString('app-secret-value', $raw);
    }

    public function test_new_key_updates_accounts_in_place_and_clears_error(): void
    {
        ConnectedAccount::create([
            'brand_id' => $this->brand->id, 'network' => Network::FacebookPage, 'external_id' => '1510742765451658',
            'name' => 'Old name', 'access_token' => 'old-tok', 'status' => AccountStatus::Error, 'last_error' => 'The Meta key stopped working.',
        ]);
        $this->saveCredentials();
        $this->fakeMeta();

        $result = $this->connect();

        $this->assertSame([], $result['problems']);
        $this->assertSame(2, ConnectedAccount::count());
        $page = ConnectedAccount::where('network', Network::FacebookPage)->sole();
        $this->assertSame('page-tok', $page->access_token);
        $this->assertSame(AccountStatus::Connected, $page->status);
        $this->assertNull($page->last_error);
    }

    public function test_missing_required_permission_blocks_and_names_it(): void
    {
        $this->saveCredentials();
        $this->fakeMeta(scopes: array_values(array_diff(MetaConnector::REQUIRED, ['instagram_content_publish'])));

        $result = $this->connect();

        $this->assertStringContainsString('instagram_content_publish', implode(' ', $result['problems']));
        $this->assertSame(0, ConnectedAccount::count());
    }

    public function test_extra_permissions_warn_but_connect(): void
    {
        $this->saveCredentials();
        $this->fakeMeta(scopes: [...MetaConnector::REQUIRED, ...MetaConnector::WANTED, 'public_profile', 'ads_management']);

        $result = $this->connect();

        $this->assertSame([], $result['problems']);
        $this->assertStringContainsString('ads_management', implode(' ', $result['warnings']));
        $this->assertStringNotContainsString('public_profile', implode(' ', $result['warnings']));
        $this->assertSame(2, ConnectedAccount::count());
    }

    public function test_key_for_another_app_is_refused(): void
    {
        $this->saveCredentials();
        $this->fakeMeta(debug: ['app_id' => '999']);

        $result = $this->connect();

        $this->assertStringContainsString('different Meta app', implode(' ', $result['problems']));
        $this->assertSame(0, ConnectedAccount::count());
    }

    public function test_invalid_key_is_explained(): void
    {
        $this->saveCredentials();
        $this->fakeMeta(debug: ['is_valid' => false]);

        $result = $this->connect();

        $this->assertStringContainsString('not valid', implode(' ', $result['problems']));
        $this->assertSame(0, ConnectedAccount::count());
    }

    public function test_garbled_key_is_reported_as_not_valid(): void
    {
        $this->saveCredentials();
        $this->fakeMeta(debug: ['is_valid' => false, 'app_id' => null]);

        $problems = implode(' ', $this->connect()['problems']);

        $this->assertStringContainsString('not valid', $problems);
        $this->assertStringNotContainsString('different Meta app', $problems);
    }

    public function test_wrong_app_secret_is_reported_as_such(): void
    {
        $this->saveCredentials();
        Http::fake(['graph.facebook.com/v27.0/debug_token*' => Http::response(['error' => ['message' => 'Invalid OAuth access token signature.', 'code' => 190]], 400)]);

        $problems = implode(' ', $this->connect()['problems']);

        $this->assertStringContainsString('App Secret', $problems);
        $this->assertStringNotContainsString('make a new key', strtolower($problems));
    }

    public function test_rejected_new_key_keeps_the_working_key(): void
    {
        $this->saveCredentials();
        $good = ['app_id' => '2181731786035384', 'is_valid' => true, 'expires_at' => 0, 'scopes' => [...MetaConnector::REQUIRED, ...MetaConnector::WANTED]];
        Http::fake(function (Request $r) use ($good) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
            if (str_contains($r->url(), '/debug_token')) {
                return Http::response(['data' => ($q['input_token'] ?? '') === 'EAAkey123' ? $good : ['app_id' => '2181731786035384', 'is_valid' => false]]);
            }

            return Http::response(['data' => [['id' => '1510742765451658', 'name' => 'Moggies International', 'access_token' => 'page-tok',
                'instagram_business_account' => ['id' => '17841470732347612', 'username' => 'moggies_international']]]]);
        });
        $this->connect();

        Livewire::test(MetaSettings::class)
            ->callAction('saveKey', data: ['key' => 'EAAtypo'])
            ->assertHasNoActionErrors();

        $this->assertSame('EAAkey123', MetaCredentials::key($this->brand));
        $this->assertSame([AccountStatus::Connected, AccountStatus::Connected], ConnectedAccount::all()->pluck('status')->all());
        $this->assertTrue(MetaConnector::lastCheck($this->brand)->valid);
    }

    public function test_key_that_sees_no_page_is_explained(): void
    {
        $this->saveCredentials();
        $this->fakeMeta(pages: []);

        $result = $this->connect();

        $this->assertStringContainsString("can't see any Facebook Page", implode(' ', $result['problems']));
        $this->assertSame(0, ConnectedAccount::count());
    }

    public function test_page_without_instagram_connects_page_and_warns(): void
    {
        $this->saveCredentials();
        $this->fakeMeta(pages: [['id' => '1510742765451658', 'name' => 'Moggies International', 'access_token' => 'page-tok']]);

        $result = $this->connect();

        $this->assertSame([], $result['problems']);
        $this->assertStringContainsString('no Instagram account', implode(' ', $result['warnings']));
        $this->assertSame(1, ConnectedAccount::where('network', Network::FacebookPage)->count());
        $this->assertSame(0, ConnectedAccount::where('network', Network::Instagram)->count());
    }

    public function test_key_needs_the_app_secret_first(): void
    {
        Http::fake();

        Livewire::test(MetaSettings::class)
            ->callAction('saveKey', data: ['key' => 'EAAkey123'])
            ->assertHasActionErrors(['key']);

        $this->assertNull(MetaCredentials::key($this->brand));
        Http::assertNothingSent();
    }

    public function test_page_never_shows_secrets(): void
    {
        $this->saveCredentials();
        $this->fakeMeta();
        $this->connect();

        Livewire::test(MetaSettings::class)
            ->assertDontSee('EAAkey123')
            ->assertDontSee('app-secret-value')
            ->assertDontSee('page-tok')
            ->assertSee('Saved on')
            ->assertSee('@moggies_international');
    }
}
