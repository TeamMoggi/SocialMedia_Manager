<?php

namespace Tests\Feature;

use App\Domain\LinkedIn\LinkedInCredentials;
use App\Domain\LinkedIn\SignInResult;
use App\Enums\Network;
use App\Http\Controllers\LinkedInAuthController;
use App\Models\Brand;
use App\Models\ConnectedAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LinkedInSignInTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->brand = Brand::where('slug', 'moggies-international')->firstOrFail();
        LinkedInCredentials::saveApp('77qh6eszwh69s9', 'client-secret-value');
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
    }

    private function fakeLinkedIn(): void
    {
        Http::fake([
            'www.linkedin.com/oauth/v2/accessToken' => Http::response(['access_token' => 'AQXkey', 'expires_in' => 5184000, 'scope' => 'rw_organization_admin,w_organization_social,r_organization_social']),
            'api.linkedin.com/rest/organizationAcls*' => Http::response(['elements' => [['role' => 'ADMINISTRATOR', 'state' => 'APPROVED', 'organization' => 'urn:li:organization:145339181']]]),
            'api.linkedin.com/rest/organizations/*' => Http::response(['localizedName' => 'Moggies International']),
        ]);
    }

    /** @return array{state: string, brand_id: int, port: int, at: int} */
    private function startSignIn(): array
    {
        $this->get('http://localhost:8091/moggies-international/linkedin/start')->assertRedirect();

        return session('linkedin.oauth');
    }

    private function account(): ?ConnectedAccount
    {
        return ConnectedAccount::where('network', Network::LinkedInOrganization)->first();
    }

    public function test_start_redirects_to_linkedin_with_state_in_session(): void
    {
        $response = $this->get('http://localhost:8091/moggies-international/linkedin/start');

        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://www.linkedin.com/oauth/v2/authorization?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
        $this->assertSame('http://localhost:8091/linkedin/callback', $q['redirect_uri']);
        $this->assertSame('rw_organization_admin w_organization_social r_organization_social', $q['scope']);
        $saved = session('linkedin.oauth');
        $this->assertSame($saved['state'], $q['state']);
        $this->assertGreaterThanOrEqual(40, strlen($saved['state']));
        $this->assertSame($this->brand->id, $saved['brand_id']);
        $this->assertSame(8091, $saved['port']);
    }

    public function test_start_without_client_secret_goes_back_with_reason(): void
    {
        \App\Models\Setting::query()->whereKey('linkedin.client_secret')->delete();

        $this->get('http://localhost:8091/moggies-international/linkedin/start')
            ->assertRedirect('http://127.0.0.1:8091/moggies-international/linkedin');

        $this->assertNull(session('linkedin.oauth'));
        $this->assertStringContainsString('Save the Client Secret first', SignInResult::peek()['title']);
    }

    public function test_start_url_uses_localhost_and_port(): void
    {
        $this->assertSame('http://localhost:8091/moggies-international/linkedin/start', LinkedInAuthController::startUrl($this->brand, 8091));
        $this->assertSame('http://localhost:8090/moggies-international/linkedin/start', LinkedInAuthController::startUrl($this->brand, 8090));
    }

    public function test_callback_success_connects_and_redirects_to_page_on_127(): void
    {
        $this->fakeLinkedIn();
        $saved = $this->startSignIn();

        $this->withSession(['linkedin.oauth' => $saved])
            ->get('http://localhost:8091/linkedin/callback?code=code-123&state='.$saved['state'])
            ->assertRedirect('http://127.0.0.1:8091/moggies-international/linkedin');

        $this->assertSame('AQXkey', $this->account()?->access_token);
        $result = SignInResult::peek();
        $this->assertTrue($result['ok']);
        $this->assertStringStartsWith('Connected: Moggies International', $result['title']);
        $this->assertSame($this->brand->id, $result['brand_id']);
    }

    public function test_callback_cancel_saves_nothing(): void
    {
        $saved = $this->startSignIn();
        Http::fake();

        $this->withSession(['linkedin.oauth' => $saved])
            ->get('http://localhost:8091/linkedin/callback?error=user_cancelled_authorize&error_description=x&state='.$saved['state'])
            ->assertRedirect('http://127.0.0.1:8091/moggies-international/linkedin');

        $this->assertNull($this->account());
        $this->assertSame('You cancelled on LinkedIn. Nothing changed.', SignInResult::peek()['title']);
        Http::assertNothingSent();
    }

    public function test_callback_with_wrong_or_missing_state_refuses(): void
    {
        $saved = $this->startSignIn();
        Http::fake();

        $this->withSession(['linkedin.oauth' => $saved])
            ->get('http://localhost:8091/linkedin/callback?code=code-123&state=forged')
            ->assertRedirect('http://127.0.0.1:8091/moggies-international/linkedin');
        $this->assertSame('This sign-in link has expired. Press Connect LinkedIn again.', SignInResult::peek()['title']);

        // No sign-in in this browser at all (session lost): back to the app's start page.
        $this->get('http://localhost:8091/linkedin/callback?code=code-123&state='.$saved['state'])
            ->assertRedirect('http://127.0.0.1:8091/');

        $this->assertNull($this->account());
        Http::assertNothingSent();
    }

    public function test_callback_used_twice_refuses_second(): void
    {
        $this->fakeLinkedIn();
        $saved = $this->startSignIn();
        $this->withSession(['linkedin.oauth' => $saved])->get('http://localhost:8091/linkedin/callback?code=code-123&state='.$saved['state']);
        $this->assertNull(session('linkedin.oauth'));

        // The session no longer holds the sign-in: a second use is refused without asking LinkedIn again.
        $sent = count(Http::recorded());
        $this->get('http://localhost:8091/linkedin/callback?code=code-123&state='.$saved['state']);
        $this->assertSame($sent, count(Http::recorded()));
        $this->assertSame('This sign-in link has expired. Press Connect LinkedIn again.', SignInResult::peek()['title']);
    }

    public function test_callback_after_ten_minutes_refuses(): void
    {
        $saved = $this->startSignIn();
        Http::fake();
        $this->travel(11)->minutes();

        $this->withSession(['linkedin.oauth' => $saved])
            ->get('http://localhost:8091/linkedin/callback?code=code-123&state='.$saved['state']);

        $this->assertNull($this->account());
        $this->assertSame('This sign-in link has expired. Press Connect LinkedIn again.', SignInResult::peek()['title']);
        Http::assertNothingSent();
    }

    public function test_callback_response_never_contains_code_or_key(): void
    {
        $this->fakeLinkedIn();
        $saved = $this->startSignIn();

        $response = $this->withSession(['linkedin.oauth' => $saved])
            ->get('http://localhost:8091/linkedin/callback?code=code-123&state='.$saved['state']);

        $everything = $response->getContent().json_encode($response->headers->all()).json_encode(SignInResult::peek());
        foreach (['AQXkey', 'code-123', 'client-secret-value'] as $secret) {
            $this->assertStringNotContainsString($secret, $everything);
        }
    }

    public function test_result_is_shown_once(): void
    {
        SignInResult::put($this->brand->id, true, 'Connected: X', null);

        $this->assertSame('Connected: X', SignInResult::take($this->brand)['title']);
        $this->assertNull(SignInResult::take($this->brand));
    }
}
