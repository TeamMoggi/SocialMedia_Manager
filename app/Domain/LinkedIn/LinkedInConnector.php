<?php

namespace App\Domain\LinkedIn;

use App\Enums\AccountStatus;
use App\Enums\Network;
use App\Models\Brand;
use App\Models\ConnectedAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

/**
 * Connects a brand's LinkedIn company page: swaps the sign-in code for a key, checks the key's
 * permissions and that the signed-in member administers the brand's page, and only then saves the
 * page as a ConnectedAccount. A refused reconnect never touches a key that already works.
 */
final class LinkedInConnector
{
    /** Asked for at sign-in (design §7.1: the minimum for connecting, posting and reading back posts). */
    public const SCOPES = ['rw_organization_admin', 'w_organization_social', 'r_organization_social'];

    /** Without these the app cannot find the page or post: connecting is refused. */
    public const REQUIRED = ['rw_organization_admin', 'w_organization_social'];

    /** Needed to read the page's own posts back: a warning when missing. */
    public const WANTED = ['r_organization_social'];

    public function __construct(private readonly LinkedInApi $api) {}

    public function connect(Brand $brand, #[\SensitiveParameter] string $code, string $redirectUri): ConnectResult
    {
        try {
            $token = $this->api->exchangeCode($code, $redirectUri);
        } catch (LinkedInError $e) {
            return ConnectResult::refused($e->plain());
        }

        $missing = array_values(array_diff(self::REQUIRED, $token['scopes']));
        if ($missing !== []) {
            return ConnectResult::refused('LinkedIn gave the app no permission for: '.implode(', ', $missing).'. Press Connect LinkedIn again and allow everything it asks for.'.$this->unchanged($brand));
        }
        $warnings = [];
        $missingWanted = array_values(array_diff(self::WANTED, $token['scopes']));
        if ($missingWanted !== []) {
            $warnings[] = 'LinkedIn left out: '.implode(', ', $missingWanted).'. Reading back the page\'s posts will need it; reconnect to add it.';
        }

        try {
            $administered = $this->api->adminOrganizations($token['access_token']);
        } catch (LinkedInError $e) {
            return ConnectResult::refused($e->plain().$this->unchanged($brand));
        }

        $orgId = LinkedInCredentials::orgId($brand);
        if ($orgId === null) {
            if (count($administered) !== 1) {
                return ConnectResult::refused(count($administered) === 0
                    ? 'This LinkedIn account is not an Administrator of any company page.'
                    : 'This LinkedIn account administers '.count($administered).' pages ('.implode(', ', $administered).'): the app cannot tell which one belongs to '.$brand->name.'.');
            }
            $orgId = $administered[0];
        } elseif (! in_array($orgId, $administered, true)) {
            return ConnectResult::refused("This LinkedIn account is not an Administrator of the LinkedIn page {$orgId}. Sign in on LinkedIn with the account that manages the page, then press Connect LinkedIn again.".$this->unchanged($brand));
        }

        $name = $this->api->organizationName($orgId, $token['access_token']);
        if ($name === null) {
            $warnings[] = "LinkedIn did not give the page's name; the page is shown by its number {$orgId}.";
        }

        $existing = ConnectedAccount::where('brand_id', $brand->id)->where('network', Network::LinkedInOrganization)->where('external_id', $orgId)->first();
        $account = ConnectedAccount::updateOrCreate(
            ['brand_id' => $brand->id, 'network' => Network::LinkedInOrganization, 'external_id' => $orgId],
            [
                'name' => $name ?? $orgId,
                'access_token' => $token['access_token'],
                'token_expires_at' => $token['expires_in'] > 0 ? CarbonImmutable::now('UTC')->addSeconds($token['expires_in']) : null,
                'status' => AccountStatus::Connected,
                'last_error' => null,
                'meta' => [
                    'via' => 'oauth',
                    'connected_at' => CarbonImmutable::now('UTC')->toIso8601String(),
                    'scopes' => $token['scopes'],
                    'role' => 'ADMINISTRATOR',
                ] + Arr::except($existing?->meta ?? [], ['error_from', 'via', 'connected_at', 'scopes', 'role']),
            ],
        );
        LinkedInCredentials::saveOrgId($brand, $orgId);

        return new ConnectResult($account, [], $warnings);
    }

    /**
     * Asks LinkedIn whether the saved key still works for the brand's page.
     *
     * @return ?string null when it works; otherwise the problem for the owner.
     *
     * @throws LinkedInError when the answer says nothing about the key: no connection, LinkedIn's own
     *                       trouble, its daily limit, or a reply that reconnecting cannot fix (a retired
     *                       API version, a malformed request).
     */
    public function check(Brand $brand): ?string
    {
        $account = $this->account($brand);
        if ($account === null || ! $account->access_token) {
            return 'LinkedIn is not connected yet.';
        }

        try {
            $administered = $this->api->adminOrganizations($account->access_token);
        } catch (LinkedInError $e) {
            // Only a refused key (401) or refused access (403) is about the connection; reconnecting fixes those.
            if ($e->keyRefused() || $e->status() === 403) {
                return $e->plain();
            }
            throw $e;
        }

        return in_array($account->external_id, $administered, true)
            ? null
            : "The LinkedIn account that connected the app is no longer an Administrator of the page {$account->name}. Press Reconnect LinkedIn and sign in with the page's Administrator.";
    }

    /**
     * Runs check() and records the answer on the brand's LinkedIn account, for the daily job and the
     * page's "Check the key now" alike: a problem puts it in Error (error_from=linkedin_key); a working
     * key clears only an error this check set.
     *
     * @return array{problem: ?string, newly_broken: bool}
     *
     * @throws LinkedInError as check() does; nothing is changed then.
     */
    public function checkAndRecord(Brand $brand): array
    {
        $problem = $this->check($brand);
        $account = $this->account($brand);
        if ($account === null) {
            return ['problem' => $problem, 'newly_broken' => false];
        }

        if ($problem === null) {
            if ($account->status === AccountStatus::Error && ($account->meta['error_from'] ?? null) === 'linkedin_key') {
                $account->update(['status' => AccountStatus::Connected, 'last_error' => null, 'meta' => Arr::except($account->meta ?? [], 'error_from')]);
            }

            return ['problem' => null, 'newly_broken' => false];
        }

        $newlyBroken = $account->status !== AccountStatus::Error;
        $account->update([
            'status' => AccountStatus::Error,
            'last_error' => $problem,
            'meta' => ['error_from' => 'linkedin_key'] + ($account->meta ?? []),
        ]);

        return ['problem' => $problem, 'newly_broken' => $newlyBroken];
    }

    private function account(Brand $brand): ?ConnectedAccount
    {
        return ConnectedAccount::where('brand_id', $brand->id)->where('network', Network::LinkedInOrganization)->first();
    }

    private function unchanged(Brand $brand): string
    {
        return ConnectedAccount::where('brand_id', $brand->id)->where('network', Network::LinkedInOrganization)->exists()
            ? ' The saved LinkedIn connection was not changed.'
            : '';
    }
}
