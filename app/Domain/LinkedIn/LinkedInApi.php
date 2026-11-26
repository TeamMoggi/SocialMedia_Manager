<?php

namespace App\Domain\LinkedIn;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The one way the app talks to LinkedIn: the sign-in (authorization code flow) and the REST API
 * with the pinned version from config('smm.linkedin.version').
 */
final class LinkedInApi
{
    public function __construct(private readonly string $clientId, #[\SensitiveParameter] private readonly ?string $clientSecret) {}

    public static function make(): self
    {
        return new self(LinkedInCredentials::clientId(), LinkedInCredentials::clientSecret());
    }

    /** @param list<string> $scopes */
    public function authorizeUrl(string $redirectUri, string $state, array $scopes): string
    {
        return $this->authBase().'/authorization?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => implode(' ', $scopes),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{access_token: string, expires_in: int, scopes: list<string>} */
    public function exchangeCode(#[\SensitiveParameter] string $code, string $redirectUri): array
    {
        try {
            $response = $this->client()->asForm()->post($this->authBase().'/accessToken', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => $this->clientId,
                'client_secret' => (string) $this->clientSecret,
                'redirect_uri' => $redirectUri,
            ]);
        } catch (ConnectionException) {
            throw LinkedInError::offline();
        }

        $json = $response->json();
        if ($response->failed() || ! is_array($json) || empty($json['access_token'])) {
            throw LinkedInError::fromResponse($response->status(), is_array($json) ? $json : [], signIn: true);
        }

        return [
            'access_token' => (string) $json['access_token'],
            'expires_in' => (int) ($json['expires_in'] ?? 0),
            'scopes' => self::parseScopes((string) ($json['scope'] ?? '')),
        ];
    }

    /** @return list<string> Ids of the company pages the signed-in member administers (approved roles only). */
    public function adminOrganizations(#[\SensitiveParameter] string $key): array
    {
        $json = $this->get('organizationAcls', ['q' => 'roleAssignee', 'role' => 'ADMINISTRATOR', 'state' => 'APPROVED'], $key);
        $ids = [];
        foreach ((array) ($json['elements'] ?? []) as $element) {
            $urn = (string) ($element['organization'] ?? $element['organizationTarget'] ?? '');
            if (preg_match('/^urn:li:organization:(\d+)$/', $urn, $m)) {
                $ids[] = $m[1];
            }
        }

        return array_values(array_unique($ids));
    }

    /** The page's name, or null when LinkedIn will not say. */
    public function organizationName(string $id, #[\SensitiveParameter] string $key): ?string
    {
        try {
            $name = $this->get('organizations/'.rawurlencode($id), [], $key)['localizedName'] ?? null;
        } catch (LinkedInError) {
            return null;
        }

        return is_string($name) && $name !== '' ? $name : null;
    }

    /** @return list<string> LinkedIn returns scopes comma-separated; spaces are accepted too. */
    public static function parseScopes(string $scope): array
    {
        return array_values(array_filter(preg_split('/[\s,]+/', $scope) ?: [], fn (string $s): bool => $s !== ''));
    }

    private function get(string $path, array $query, #[\SensitiveParameter] string $key): array
    {
        $url = rtrim((string) config('smm.linkedin.rest_url'), '/').'/'.$path.($query !== [] ? '?'.http_build_query($query) : '');
        try {
            $response = $this->client()
                ->withToken($key)
                ->withHeaders(['LinkedIn-Version' => (string) config('smm.linkedin.version'), 'X-Restli-Protocol-Version' => '2.0.0'])
                ->get($url);
        } catch (ConnectionException) {
            throw LinkedInError::offline();
        }

        return $this->decode($response);
    }

    private function decode(Response $response): array
    {
        $json = $response->json();
        if ($response->failed()) {
            throw LinkedInError::fromResponse($response->status(), is_array($json) ? $json : []);
        }

        return is_array($json) ? $json : [];
    }

    private function client(): PendingRequest
    {
        return Http::timeout(30)->acceptJson();
    }

    private function authBase(): string
    {
        return rtrim((string) config('smm.linkedin.auth_url'), '/');
    }
}
