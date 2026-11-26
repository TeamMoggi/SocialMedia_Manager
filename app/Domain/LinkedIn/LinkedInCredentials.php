<?php

namespace App\Domain\LinkedIn;

use App\Models\Brand;
use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * The LinkedIn developer app's Client ID and Client Secret (one per app) and each brand's company
 * page id. The Client Secret is typed in by the owner, stored encrypted with Setting::putSecret and
 * only ever shown back as "Saved on <date>".
 */
final class LinkedInCredentials
{
    public static function clientId(): string
    {
        return (string) (Setting::get('linkedin.client_id') ?: config('smm.linkedin.client_id'));
    }

    public static function clientSecret(): ?string
    {
        return Setting::getSecret('linkedin.client_secret');
    }

    public static function saveApp(string $clientId, #[\SensitiveParameter] string $secret): void
    {
        Setting::put('linkedin.client_id', self::clean($clientId));
        Setting::putSecret('linkedin.client_secret', self::clean($secret));
        Setting::put('linkedin.client_secret_saved_at', CarbonImmutable::now('UTC')->toIso8601String());
    }

    public static function savedAt(): ?CarbonImmutable
    {
        $value = Setting::get('linkedin.client_secret_saved_at');

        return $value ? CarbonImmutable::parse($value) : null;
    }

    /** The brand's LinkedIn company page id; Moggies International's is known from the start. */
    public static function orgId(Brand $brand): ?string
    {
        $saved = Setting::get("linkedin.org_id.{$brand->id}");
        if (is_string($saved) && $saved !== '') {
            return $saved;
        }

        return $brand->slug === 'moggies-international' ? (string) config('smm.linkedin.default_org_id') : null;
    }

    public static function saveOrgId(Brand $brand, string $id): void
    {
        Setting::put("linkedin.org_id.{$brand->id}", $id);
    }

    /** Copying from the portal can bring spaces and line breaks along; IDs and secrets never contain any. */
    public static function clean(#[\SensitiveParameter] string $value): string
    {
        return (string) preg_replace('/\s+/', '', $value);
    }
}
