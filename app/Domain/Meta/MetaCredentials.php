<?php

namespace App\Domain\Meta;

use App\Models\Brand;
use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * The Meta App Secret (one per app) and each brand's permanent system-user key. Both are typed in
 * by the owner, stored encrypted with Setting::putSecret and only ever shown back as "Saved on <date>".
 */
final class MetaCredentials
{
    public static function appId(): string
    {
        return (string) (Setting::get('meta.app_id') ?: config('smm.meta.app_id'));
    }

    public static function appSecret(): ?string
    {
        return Setting::getSecret('meta.app_secret');
    }

    public static function saveAppSecret(string $appId, #[\SensitiveParameter] string $secret): void
    {
        Setting::put('meta.app_id', self::clean($appId));
        Setting::putSecret('meta.app_secret', self::clean($secret));
        Setting::put('meta.app_secret_saved_at', CarbonImmutable::now('UTC')->toIso8601String());
    }

    public static function key(Brand $brand): ?string
    {
        return Setting::getSecret("meta.key.{$brand->id}");
    }

    public static function saveKey(Brand $brand, #[\SensitiveParameter] string $key): void
    {
        Setting::putSecret("meta.key.{$brand->id}", self::clean($key));
        Setting::put("meta.key_saved_at.{$brand->id}", CarbonImmutable::now('UTC')->toIso8601String());
    }

    /** @param 'app_secret'|'key' $what */
    public static function savedAt(string $what, ?Brand $brand = null): ?CarbonImmutable
    {
        $value = $what === 'key'
            ? Setting::get("meta.key_saved_at.{$brand?->id}")
            : Setting::get('meta.app_secret_saved_at');

        return $value ? CarbonImmutable::parse($value) : null;
    }

    /** Copying from Meta's dialog can bring spaces and line breaks along; keys and secrets never contain any. */
    public static function clean(#[\SensitiveParameter] string $value): string
    {
        return (string) preg_replace('/\s+/', '', $value);
    }
}
