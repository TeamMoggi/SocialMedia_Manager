<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\Network;
use App\Models\Brand;
use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LinkedInKeyDailyCheckTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->brand = Brand::where('slug', 'moggies-international')->firstOrFail();
    }

    private function connected(array $extra = []): ConnectedAccount
    {
        return ConnectedAccount::create($extra + [
            'brand_id' => $this->brand->id, 'network' => Network::LinkedInOrganization, 'external_id' => '145339181',
            'name' => 'Moggies International', 'access_token' => 'AQXkey', 'token_expires_at' => now()->addDays(50),
        ]);
    }

    private function acls(array $ids): void
    {
        Http::fake(['api.linkedin.com/rest/organizationAcls*' => Http::response(['elements' => array_map(
            fn (string $id): array => ['role' => 'ADMINISTRATOR', 'state' => 'APPROVED', 'organization' => "urn:li:organization:{$id}"],
            $ids,
        )])]);
    }

    private function notificationTitles(): array
    {
        return User::where('email', User::LOCAL_OWNER_EMAIL)->firstOrFail()->notifications->pluck('data.title')->all();
    }

    public function test_refused_key_marks_error_and_notifies_once(): void
    {
        $account = $this->connected();
        Http::fake(['api.linkedin.com/*' => Http::response(['status' => 401, 'message' => 'Invalid access token'], 401)]);

        $this->artisan('smm:check-accounts')->expectsOutputToContain('LinkedIn key: LinkedIn no longer accepts the key')->assertExitCode(0);
        $this->artisan('smm:check-accounts')->assertExitCode(0);

        $account->refresh();
        $this->assertSame(AccountStatus::Error, $account->status);
        $this->assertStringContainsString('Reconnect LinkedIn', $account->last_error);
        $this->assertSame('linkedin_key', $account->meta['error_from']);
        $this->assertSame(['LinkedIn key stopped working'], $this->notificationTitles());
    }

    public function test_page_gone_marks_error_with_reason(): void
    {
        $account = $this->connected();
        $this->acls(['999']);

        $this->artisan('smm:check-accounts')->assertExitCode(0);

        $account->refresh();
        $this->assertSame(AccountStatus::Error, $account->status);
        $this->assertStringContainsString('no longer an Administrator', $account->last_error);
    }

    public function test_working_key_clears_only_its_own_error(): void
    {
        $own = $this->connected(['status' => AccountStatus::Error, 'last_error' => 'refused', 'meta' => ['error_from' => 'linkedin_key', 'via' => 'oauth']]);
        $this->acls(['145339181']);

        $this->artisan('smm:check-accounts')->expectsOutputToContain('LinkedIn key: works.')->assertExitCode(0);

        $own->refresh();
        $this->assertSame(AccountStatus::Connected, $own->status);
        $this->assertNull($own->last_error);
        $this->assertSame(['via' => 'oauth'], $own->meta);

        $own->update(['status' => AccountStatus::Error, 'last_error' => 'something else', 'meta' => ['error_from' => 'other']]);
        $this->artisan('smm:check-accounts')->assertExitCode(0);
        $this->assertSame(AccountStatus::Error, $own->fresh()->status);
    }

    public function test_offline_or_rate_limited_changes_nothing(): void
    {
        $account = $this->connected();
        $responses = [
            fn () => throw new ConnectionException('down'),
            fn () => Http::response(['status' => 429, 'message' => 'Too many'], 429),
            fn () => Http::response(['status' => 503, 'message' => 'Unavailable'], 503),
        ];
        foreach ($responses as $response) {
            $current = $response;
            Http::fake(fn () => $current());

            $this->artisan('smm:check-accounts')->expectsOutputToContain('LinkedIn key: could not check now')->assertExitCode(0);

            $account->refresh();
            $this->assertSame(AccountStatus::Connected, $account->status);
            $this->assertNull($account->last_error);
        }
        $this->assertSame([], $this->notificationTitles());
    }

    public function test_expiry_warning_still_comes_from_account_health(): void
    {
        $account = $this->connected(['token_expires_at' => now()->addDays(5)]);
        $this->acls(['145339181']);

        $this->artisan('smm:check-accounts')->assertExitCode(0);

        $this->assertSame(AccountStatus::Expiring, $account->fresh()->status);
        $this->assertSame(['Moggies International: access expires soon'], $this->notificationTitles());
    }

    public function test_brand_without_linkedin_account_makes_no_request(): void
    {
        Http::fake();

        $this->artisan('smm:check-accounts')->assertExitCode(0);

        Http::assertNothingSent();
    }
}
