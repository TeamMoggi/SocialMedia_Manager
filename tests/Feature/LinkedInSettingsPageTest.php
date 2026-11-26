<?php

namespace Tests\Feature;

use App\Domain\LinkedIn\LinkedInCredentials;
use App\Domain\LinkedIn\SignInResult;
use App\Enums\Network;
use App\Filament\Pages\LinkedInSettings;
use App\Http\Controllers\LinkedInAuthController;
use App\Models\Brand;
use App\Models\ConnectedAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class LinkedInSettingsPageTest extends TestCase
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

    private function connected(): ConnectedAccount
    {
        return ConnectedAccount::create([
            'brand_id' => $this->brand->id, 'network' => Network::LinkedInOrganization, 'external_id' => '145339181',
            'name' => 'Moggies International', 'access_token' => 'AQXkey',
            'token_expires_at' => CarbonImmutable::parse('2027-01-25 06:00:00', 'UTC'),
        ]);
    }

    public function test_save_app_details_stores_secret_and_never_shows_it(): void
    {
        Livewire::test(LinkedInSettings::class)
            ->callAction('saveApp', data: ['client_id' => '77qh6eszwh69s9', 'client_secret' => ' topsecret '])
            ->assertHasNoActionErrors()
            ->assertSee('Saved on')
            ->assertDontSee('topsecret');

        $this->assertSame('topsecret', LinkedInCredentials::clientSecret());
    }

    public function test_connect_action_points_to_localhost_start(): void
    {
        Livewire::test(LinkedInSettings::class)->assertActionHidden('connect');

        LinkedInCredentials::saveApp('77qh6eszwh69s9', 'topsecret');

        Livewire::test(LinkedInSettings::class)
            ->assertActionVisible('connect')
            ->assertActionHasUrl('connect', LinkedInAuthController::startUrl($this->brand, (int) config('smm.port')))
            ->assertActionHasLabel('connect', 'Connect LinkedIn');
    }

    public function test_page_explains_what_to_do_before_the_secret_is_saved(): void
    {
        Livewire::test(LinkedInSettings::class)->assertSee('Save the Client Secret first');
    }

    public function test_page_shows_connected_page_and_expiry(): void
    {
        $this->connected();

        Livewire::test(LinkedInSettings::class)
            ->assertSee('Moggies International')
            ->assertSee('145339181')
            ->assertSee('25 Jan 2027')
            ->assertDontSee('AQXkey');
    }

    public function test_label_changes_to_reconnect_once_connected(): void
    {
        LinkedInCredentials::saveApp('77qh6eszwh69s9', 'topsecret');
        $this->connected();

        Livewire::test(LinkedInSettings::class)->assertActionHasLabel('connect', 'Reconnect LinkedIn');
    }

    public function test_check_now_reports_result(): void
    {
        LinkedInCredentials::saveApp('77qh6eszwh69s9', 'topsecret');
        $this->connected();
        Http::fake(['api.linkedin.com/rest/organizationAcls*' => Http::sequence()
            ->push(['elements' => [['role' => 'ADMINISTRATOR', 'state' => 'APPROVED', 'organization' => 'urn:li:organization:145339181']]])
            ->push(['status' => 401, 'message' => 'Invalid access token'], 401)]);

        Livewire::test(LinkedInSettings::class)->callAction('checkNow')->assertNotified('LinkedIn key works');
        Livewire::test(LinkedInSettings::class)->callAction('checkNow')->assertNotified('LinkedIn key problem');
    }

    public function test_check_now_records_the_result_like_the_daily_check(): void
    {
        LinkedInCredentials::saveApp('77qh6eszwh69s9', 'topsecret');
        $account = $this->connected();
        Http::fake(['api.linkedin.com/rest/organizationAcls*' => Http::sequence()
            ->push(['status' => 401, 'message' => 'Invalid access token'], 401)
            ->push(['elements' => [['role' => 'ADMINISTRATOR', 'state' => 'APPROVED', 'organization' => 'urn:li:organization:145339181']]])]);

        Livewire::test(LinkedInSettings::class)->callAction('checkNow');
        $account->refresh();
        $this->assertSame(\App\Enums\AccountStatus::Error, $account->status);
        $this->assertSame('linkedin_key', $account->meta['error_from']);

        Livewire::test(LinkedInSettings::class)->callAction('checkNow')->assertNotified('LinkedIn key works');
        $account->refresh();
        $this->assertSame(\App\Enums\AccountStatus::Connected, $account->status);
        $this->assertNull($account->last_error);
    }

    public function test_check_now_hidden_until_connected(): void
    {
        Livewire::test(LinkedInSettings::class)->assertActionHidden('checkNow');
    }

    public function test_sign_in_result_is_shown_as_notification_once(): void
    {
        SignInResult::put($this->brand->id, true, 'Connected: Moggies International, key valid until 25 Jan 2027', null);

        Livewire::test(LinkedInSettings::class)->assertNotified('Connected: Moggies International, key valid until 25 Jan 2027');
        $this->assertNull(SignInResult::peek());
    }
}
