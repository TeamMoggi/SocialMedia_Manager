<?php

namespace Tests\Feature;

use App\Domain\LinkedIn\LinkedInConnector;
use App\Domain\LinkedIn\LinkedInCredentials;
use App\Domain\LinkedIn\LinkedInError;
use App\Enums\AccountStatus;
use App\Enums\Network;
use App\Models\Brand;
use App\Models\ConnectedAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LinkedInConnectTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const REDIRECT = 'http://localhost:8091/linkedin/callback';

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->brand = Brand::where('slug', 'moggies-international')->firstOrFail();
        LinkedInCredentials::saveApp('77qh6eszwh69s9', 'client-secret-value');
    }

    /** @param list<string> $admin */
    private function fakeLinkedIn(?string $scope = null, array $admin = ['145339181'], int $nameStatus = 200): void
    {
        $scope ??= 'rw_organization_admin,w_organization_social,r_organization_social';
        Http::fake([
            'www.linkedin.com/oauth/v2/accessToken' => Http::response(['access_token' => 'AQXkey', 'expires_in' => 5184000, 'scope' => $scope]),
            'api.linkedin.com/rest/organizationAcls*' => Http::response(['elements' => array_map(
                fn (string $id): array => ['role' => 'ADMINISTRATOR', 'state' => 'APPROVED', 'organization' => "urn:li:organization:{$id}"],
                $admin,
            )]),
            'api.linkedin.com/rest/organizations/*' => $nameStatus === 200
                ? Http::response(['localizedName' => 'Moggies International'])
                : Http::response(['status' => $nameStatus, 'message' => 'no'], $nameStatus),
        ]);
    }

    private function connect(): \App\Domain\LinkedIn\ConnectResult
    {
        return app(LinkedInConnector::class)->connect($this->brand, 'code-123', self::REDIRECT);
    }

    private function account(): ?ConnectedAccount
    {
        return ConnectedAccount::where('brand_id', $this->brand->id)->where('network', Network::LinkedInOrganization)->first();
    }

    private function existingAccount(): ConnectedAccount
    {
        return ConnectedAccount::create([
            'brand_id' => $this->brand->id, 'network' => Network::LinkedInOrganization, 'external_id' => '145339181',
            'name' => 'Moggies International', 'access_token' => 'OLDkey', 'token_expires_at' => now()->addDays(30),
        ]);
    }

    public function test_connect_saves_the_page_with_key_and_expiry(): void
    {
        $this->freezeSecond();
        $this->fakeLinkedIn();

        $result = $this->connect();

        $this->assertSame([], $result->problems);
        $this->assertSame([], $result->warnings);
        $account = $this->account();
        $this->assertNotNull($account);
        $this->assertTrue($result->account->is($account));
        $this->assertSame('145339181', $account->external_id);
        $this->assertSame('Moggies International', $account->name);
        $this->assertSame('AQXkey', $account->access_token);
        $this->assertTrue($account->token_expires_at->equalTo(now()->addSeconds(5184000)));
        $this->assertSame(AccountStatus::Connected, $account->status);
        $this->assertSame('oauth', $account->meta['via']);
        $this->assertSame(LinkedInConnector::SCOPES, $account->meta['scopes']);
        $this->assertSame('ADMINISTRATOR', $account->meta['role']);
        $this->assertArrayHasKey('connected_at', $account->meta);
        $this->assertSame(['via', 'connected_at', 'scopes', 'role'], array_keys($account->meta));
    }

    public function test_missing_required_scope_refuses_and_saves_nothing(): void
    {
        $this->fakeLinkedIn('rw_organization_admin,r_organization_social');

        $result = $this->connect();

        $this->assertNull($result->account);
        $this->assertStringContainsString('w_organization_social', implode(' ', $result->problems));
        $this->assertNull($this->account());
    }

    public function test_missing_wanted_scope_connects_with_warning(): void
    {
        $this->fakeLinkedIn('rw_organization_admin,w_organization_social');

        $result = $this->connect();

        $this->assertSame([], $result->problems);
        $this->assertNotNull($this->account());
        $this->assertStringContainsString('r_organization_social', implode(' ', $result->warnings));
    }

    public function test_not_administrator_of_brand_page_refuses(): void
    {
        $this->fakeLinkedIn(admin: ['999']);

        $result = $this->connect();

        $this->assertStringContainsString('is not an Administrator of the LinkedIn page 145339181', implode(' ', $result->problems));
        $this->assertNull($this->account());
    }

    public function test_brand_without_org_id_takes_single_page_and_stores_it(): void
    {
        $this->brand = Brand::create(['name' => 'Other', 'slug' => 'other']);
        $this->fakeLinkedIn(admin: ['555']);

        $result = $this->connect();

        $this->assertSame([], $result->problems);
        $this->assertSame('555', $this->account()->external_id);
        $this->assertSame('555', LinkedInCredentials::orgId($this->brand));
    }

    public function test_brand_without_org_id_and_two_pages_refuses_listing_ids(): void
    {
        $this->brand = Brand::create(['name' => 'Other', 'slug' => 'other']);
        $this->fakeLinkedIn(admin: ['555', '777']);

        $result = $this->connect();

        $this->assertStringContainsString('555, 777', implode(' ', $result->problems));
        $this->assertNull($this->account());
        $this->assertNull(LinkedInCredentials::orgId($this->brand));
    }

    public function test_failed_reconnect_keeps_working_key(): void
    {
        $old = $this->existingAccount();
        $this->fakeLinkedIn('rw_organization_admin');

        $result = $this->connect();

        $this->assertNotSame([], $result->problems);
        $old->refresh();
        $this->assertSame('OLDkey', $old->access_token);
        $this->assertSame(1, ConnectedAccount::where('network', Network::LinkedInOrganization)->count());
    }

    public function test_successful_reconnect_replaces_key_and_clears_its_error(): void
    {
        $old = $this->existingAccount();
        $old->update(['status' => AccountStatus::Error, 'last_error' => 'refused', 'meta' => ['error_from' => 'linkedin_key']]);
        $this->fakeLinkedIn();

        $this->connect();

        $old->refresh();
        $this->assertSame('AQXkey', $old->access_token);
        $this->assertSame(AccountStatus::Connected, $old->status);
        $this->assertNull($old->last_error);
        $this->assertArrayNotHasKey('error_from', $old->meta);
    }

    public function test_name_lookup_refused_uses_id_and_warns(): void
    {
        $this->fakeLinkedIn(nameStatus: 403);

        $result = $this->connect();

        $this->assertSame('145339181', $this->account()->name);
        $this->assertStringContainsString('name', implode(' ', $result->warnings));
    }

    public function test_refused_sign_in_code_is_a_problem(): void
    {
        Http::fake(['www.linkedin.com/*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'The code has expired'], 400)]);

        $result = $this->connect();

        $this->assertStringContainsString('The code has expired', implode(' ', $result->problems));
        $this->assertNull($this->account());
    }

    public function test_check_returns_null_when_page_still_administered(): void
    {
        $this->existingAccount();
        $this->fakeLinkedIn();

        $this->assertNull(app(LinkedInConnector::class)->check($this->brand));
    }

    public function test_check_reports_refused_key(): void
    {
        $this->existingAccount();
        Http::fake(['api.linkedin.com/*' => Http::response(['status' => 401, 'message' => 'Invalid access token'], 401)]);

        $this->assertStringContainsString('Reconnect LinkedIn', (string) app(LinkedInConnector::class)->check($this->brand));
    }

    public function test_check_reports_page_gone(): void
    {
        $this->existingAccount();
        $this->fakeLinkedIn(admin: ['999']);

        $this->assertStringContainsString('no longer an Administrator', (string) app(LinkedInConnector::class)->check($this->brand));
    }

    public function test_check_throws_transient_when_offline(): void
    {
        $this->existingAccount();
        Http::fake(fn () => throw new ConnectionException('down'));

        $this->expectException(LinkedInError::class);
        app(LinkedInConnector::class)->check($this->brand);
    }
}
