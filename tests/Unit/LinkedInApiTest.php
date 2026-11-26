<?php

namespace Tests\Unit;

use App\Domain\LinkedIn\LinkedInApi;
use App\Domain\LinkedIn\LinkedInError;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LinkedInApiTest extends TestCase
{
    private const SCOPES = ['rw_organization_admin', 'w_organization_social', 'r_organization_social'];

    private function api(): LinkedInApi
    {
        return new LinkedInApi('77qh6eszwh69s9', 'client-secret-value');
    }

    public function test_authorize_url_has_code_flow_params(): void
    {
        $url = $this->api()->authorizeUrl('http://localhost:8091/linkedin/callback', 'state-abc', self::SCOPES);

        $this->assertSame('www.linkedin.com', parse_url($url, PHP_URL_HOST));
        $this->assertSame('/oauth/v2/authorization', parse_url($url, PHP_URL_PATH));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('code', $q['response_type']);
        $this->assertSame('77qh6eszwh69s9', $q['client_id']);
        $this->assertSame('http://localhost:8091/linkedin/callback', $q['redirect_uri']);
        $this->assertSame('state-abc', $q['state']);
        $this->assertSame('rw_organization_admin w_organization_social r_organization_social', $q['scope']);
    }

    public function test_exchange_code_posts_form_and_parses_scopes(): void
    {
        Http::fake(['www.linkedin.com/oauth/v2/accessToken' => Http::response([
            'access_token' => 'AQXkey', 'expires_in' => 5184000, 'scope' => 'rw_organization_admin,w_organization_social',
        ])]);

        $result = $this->api()->exchangeCode('code-123', 'http://localhost:8091/linkedin/callback');

        $this->assertSame('AQXkey', $result['access_token']);
        $this->assertSame(5184000, $result['expires_in']);
        $this->assertSame(['rw_organization_admin', 'w_organization_social'], $result['scopes']);
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->isForm()
                && $request['grant_type'] === 'authorization_code'
                && $request['code'] === 'code-123'
                && $request['client_id'] === '77qh6eszwh69s9'
                && $request['client_secret'] === 'client-secret-value'
                && $request['redirect_uri'] === 'http://localhost:8091/linkedin/callback';
        });
    }

    public function test_scope_string_with_spaces_also_parses(): void
    {
        Http::fake(['www.linkedin.com/*' => Http::response([
            'access_token' => 'AQXkey', 'expires_in' => 5184000, 'scope' => 'rw_organization_admin w_organization_social  r_organization_social',
        ])]);

        $this->assertSame(self::SCOPES, $this->api()->exchangeCode('c', 'http://localhost:8091/linkedin/callback')['scopes']);
    }

    public function test_refused_exchange_gives_linkedins_reason_without_secrets(): void
    {
        Http::fake(['www.linkedin.com/*' => Http::response(['error' => 'invalid_request', 'error_description' => 'Unable to retrieve access token: appid/redirect uri/code verifier does not match'], 400)]);

        try {
            $this->api()->exchangeCode('code-123', 'http://localhost:8091/linkedin/callback');
            $this->fail('No error thrown');
        } catch (LinkedInError $e) {
            $this->assertStringContainsString('LinkedIn refused the sign-in', $e->plain());
            $this->assertStringContainsString('does not match', $e->plain());
            $this->assertStringNotContainsString('code-123', $e->getMessage().$e->plain());
            $this->assertStringNotContainsString('client-secret-value', $e->getMessage().$e->plain());
        }
    }

    public function test_rest_calls_send_version_and_restli_headers(): void
    {
        Http::fake(['api.linkedin.com/rest/organizationAcls*' => Http::response(['elements' => [
            ['role' => 'ADMINISTRATOR', 'state' => 'APPROVED', 'organization' => 'urn:li:organization:145339181'],
            ['role' => 'ADMINISTRATOR', 'state' => 'APPROVED', 'organizationTarget' => 'urn:li:organization:999'],
        ], 'paging' => ['count' => 10, 'start' => 0]])]);

        $this->assertSame(['145339181', '999'], $this->api()->adminOrganizations('AQXkey'));
        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return str_starts_with($request->url(), 'https://api.linkedin.com/rest/organizationAcls?')
                && $q === ['q' => 'roleAssignee', 'role' => 'ADMINISTRATOR', 'state' => 'APPROVED']
                && $request->header('LinkedIn-Version') === ['202611']
                && $request->header('X-Restli-Protocol-Version') === ['2.0.0']
                && $request->header('Authorization') === ['Bearer AQXkey'];
        });
    }

    public function test_organization_name_and_refusal(): void
    {
        Http::fake([
            'api.linkedin.com/rest/organizations/145339181' => Http::response(['id' => 145339181, 'localizedName' => 'Moggies International']),
            'api.linkedin.com/rest/organizations/999' => Http::response(['status' => 403, 'message' => 'Not enough permissions'], 403),
        ]);

        $this->assertSame('Moggies International', $this->api()->organizationName('145339181', 'AQXkey'));
        $this->assertNull($this->api()->organizationName('999', 'AQXkey'));
    }

    public function test_errors_are_plain_and_never_contain_the_key(): void
    {
        $cases = [
            401 => ['keyRefused' => true, 'transient' => false, 'says' => 'Reconnect LinkedIn'],
            429 => ['keyRefused' => false, 'transient' => true, 'says' => 'daily limit'],
            500 => ['keyRefused' => false, 'transient' => true, 'says' => 'temporary problem'],
            426 => ['keyRefused' => false, 'transient' => false, 'says' => 'update the app'],
        ];
        $current = 0;
        Http::fake(function () use (&$current) {
            return Http::response(['status' => $current, 'message' => 'x'], $current);
        });
        foreach ($cases as $status => $expect) {
            $current = $status;
            try {
                $this->api()->adminOrganizations('AQXkey');
                $this->fail("No error for {$status}");
            } catch (LinkedInError $e) {
                $this->assertSame($status, $e->status());
                $this->assertSame($expect['keyRefused'], $e->keyRefused(), "keyRefused {$status}");
                $this->assertSame($expect['transient'], $e->transient(), "transient {$status}");
                $this->assertStringContainsString($expect['says'], $e->plain());
                $this->assertStringNotContainsString('AQXkey', $e->getMessage().$e->plain());
            }
        }
    }

    public function test_no_connection_is_transient(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 6'));

        try {
            $this->api()->adminOrganizations('AQXkey');
            $this->fail('No error thrown');
        } catch (LinkedInError $e) {
            $this->assertTrue($e->transient());
            $this->assertSame('Could not reach LinkedIn (no connection).', $e->plain());
        }
    }
}
