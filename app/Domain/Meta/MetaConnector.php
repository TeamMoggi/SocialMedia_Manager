<?php

namespace App\Domain\Meta;

use App\Enums\AccountStatus;
use App\Enums\Network;
use App\Models\Brand;
use App\Models\ConnectedAccount;
use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * Connects a brand's Facebook Page and its linked Instagram account with the brand's permanent
 * system-user key: checks the key with Meta (debug_token), reads the Page and its Page token from
 * me/accounts, and saves both as ConnectedAccounts. Saving a new key updates them in place.
 */
final class MetaConnector
{
    /** Without these the app cannot post: connecting is refused. */
    public const REQUIRED = ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'instagram_basic', 'instagram_content_publish'];

    /** Needed later for statistics and comments: a warning when missing. */
    public const WANTED = ['read_insights', 'instagram_manage_insights', 'pages_manage_engagement', 'instagram_manage_comments', 'pages_read_user_content', 'business_management'];

    public function __construct(private readonly MetaGraph $graph) {}

    public function checkKey(Brand $brand): KeyCheck
    {
        $key = MetaCredentials::key($brand);
        $check = $key === null ? KeyCheck::failed('No Meta key is saved yet.') : $this->askMeta($key);
        // No answer from Meta says nothing about the key: keep the last real check on the Meta page.
        if (! $check->unreachable) {
            Setting::put("meta.key_check.{$brand->id}", $check->toArray());
        }

        return $check;
    }

    public static function lastCheck(Brand $brand): ?KeyCheck
    {
        $saved = Setting::get("meta.key_check.{$brand->id}");

        return is_array($saved) ? KeyCheck::fromArray($saved) : null;
    }

    /**
     * Connects with the saved key, or with $newKey: a new key is saved only once Meta accepts it, so a
     * mistyped key never replaces a working one.
     *
     * @return array{page: ?ConnectedAccount, instagram: ?ConnectedAccount, problems: list<string>, warnings: list<string>}
     */
    public function connect(Brand $brand, #[\SensitiveParameter] ?string $newKey = null): array
    {
        $result = ['page' => null, 'instagram' => null, 'problems' => [], 'warnings' => []];
        $key = $newKey !== null ? MetaCredentials::clean($newKey) : MetaCredentials::key($brand);
        if ($key === null || $key === '') {
            $result['problems'][] = 'No Meta key is saved yet.';

            return $result;
        }

        $check = $this->askMeta($key);
        $problem = $check->problem
            ?? ($check->missing !== [] ? 'The key is missing: '.implode(', ', $check->missing).'. Make a new key and tick these.' : null);
        if ($problem !== null) {
            if ($newKey !== null && MetaCredentials::key($brand) !== null) {
                $problem .= ' Your saved key was not changed.';
            } elseif ($newKey === null && ! $check->unreachable) {
                Setting::put("meta.key_check.{$brand->id}", $check->toArray());
            }
            $result['problems'][] = $problem;

            return $result;
        }

        if ($newKey !== null) {
            MetaCredentials::saveKey($brand, $key);
        }
        Setting::put("meta.key_check.{$brand->id}", $check->toArray());
        if ($check->extra !== []) {
            $result['warnings'][] = 'The key has more access than the app needs: '.implode(', ', $check->extra).'. Next time you make a key, leave these unticked.';
        }
        if ($check->missingWanted !== []) {
            $result['warnings'][] = 'The key lacks: '.implode(', ', $check->missingWanted).'. Statistics and comments will need these later; tick them when you next make a key.';
        }

        try {
            $pages = $this->graph->get('me/accounts', ['fields' => 'id,name,access_token,instagram_business_account{id,username}'], $key)['data'] ?? [];
        } catch (MetaError $e) {
            $result['problems'][] = $e->plain();

            return $result;
        }

        if (count($pages) === 0) {
            $result['problems'][] = "The key can't see any Facebook Page. In Meta's business settings, give the system user the Page 'Moggies International'.";

            return $result;
        }
        if (count($pages) > 1) {
            $names = implode(', ', array_map(fn (array $p): string => (string) ($p['name'] ?? $p['id']), $pages));
            $result['problems'][] = 'The key can see '.count($pages)." Pages ({$names}). Give the system user only this brand's Page.";

            return $result;
        }

        $page = $pages[0];
        if (empty($page['access_token'])) {
            $result['problems'][] = 'Meta gave no Page access for this key. Check that the system user has the Page with Content access.';

            return $result;
        }

        $common = [
            'access_token' => (string) $page['access_token'],
            'token_expires_at' => $check->expiresAt,
            'status' => AccountStatus::Connected,
            'last_error' => null,
        ];
        $connectedAt = CarbonImmutable::now('UTC')->toIso8601String();

        $result['page'] = ConnectedAccount::updateOrCreate(
            ['brand_id' => $brand->id, 'network' => Network::FacebookPage, 'external_id' => (string) $page['id']],
            $common + ['name' => (string) ($page['name'] ?? $page['id']), 'meta' => ['via' => 'system_user_key', 'connected_at' => $connectedAt]],
        );

        $ig = $page['instagram_business_account'] ?? null;
        if (! is_array($ig) || empty($ig['id'])) {
            $result['warnings'][] = 'Meta shows no Instagram account linked to this Page for the key: in business settings give the system user the Instagram account.';

            return $result;
        }

        $result['instagram'] = ConnectedAccount::updateOrCreate(
            ['brand_id' => $brand->id, 'network' => Network::Instagram, 'external_id' => (string) $ig['id']],
            $common + [
                'name' => '@'.($ig['username'] ?? $ig['id']),
                'meta' => ['via' => 'system_user_key', 'connected_at' => $connectedAt, 'page_id' => (string) $page['id'], 'username' => $ig['username'] ?? null],
            ],
        );

        return $result;
    }

    private function askMeta(#[\SensitiveParameter] string $key): KeyCheck
    {
        $secret = MetaCredentials::appSecret();
        if ($secret === null) {
            return KeyCheck::failed('Save the App Secret first.');
        }

        try {
            $data = $this->graph->get('debug_token', ['input_token' => $key], MetaCredentials::appId().'|'.$secret)['data'] ?? [];
        } catch (MetaError $e) {
            // No connection, Meta's own temporary trouble or its hourly limit: nothing is known about the key.
            if ($e->transient()) {
                return KeyCheck::failed($e->plain(), unreachable: true);
            }

            // debug_token itself is called with the app's own token (App ID + App Secret), so an access
            // error here is about those, not about the key.
            return in_array($e->code(), [190, 101, 102], true)
                ? KeyCheck::failed('Meta did not accept the App ID or App Secret: check App settings → Basic on developers.facebook.com and save the App Secret again.')
                : KeyCheck::failed($e->plain());
        }

        if (($data['is_valid'] ?? false) !== true) {
            return KeyCheck::failed('Meta says this key is not valid (it may have been copied only in part, or removed). Make a new key.');
        }
        $appId = (string) ($data['app_id'] ?? '');
        if ($appId !== '' && $appId !== MetaCredentials::appId()) {
            return KeyCheck::failed("This key was made for a different Meta app. Make it for 'Moggies Social Manager'.");
        }

        $scopes = array_values(array_map('strval', $data['scopes'] ?? []));
        $expires = (int) ($data['expires_at'] ?? 0);

        return new KeyCheck(
            true,
            $expires > 0 ? CarbonImmutable::createFromTimestampUTC($expires) : null,
            $scopes,
            array_values(array_diff(self::REQUIRED, $scopes)),
            array_values(array_diff(self::WANTED, $scopes)),
            array_values(array_diff($scopes, self::REQUIRED, self::WANTED, ['public_profile'])),
            null,
            false,
            CarbonImmutable::now('UTC'),
        );
    }
}
