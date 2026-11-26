<?php

namespace App\Domain\LinkedIn;

use App\Models\Brand;
use App\Models\Setting;

/**
 * The outcome of the last LinkedIn sign-in, shown once on the LinkedIn page. Kept as a setting, not in
 * the session: the sign-in runs on localhost and the owner returns to 127.0.0.1, and browsers keep
 * separate cookies for the two. Holds only a title and a plain message, never a key or code.
 */
final class SignInResult
{
    private const KEY = 'linkedin.sign_in_result';

    public static function put(?int $brandId, bool $ok, string $title, ?string $body): void
    {
        Setting::put(self::KEY, ['brand_id' => $brandId, 'ok' => $ok, 'title' => $title, 'body' => $body]);
    }

    /** @return ?array{brand_id: ?int, ok: bool, title: string, body: ?string} */
    public static function peek(): ?array
    {
        $value = Setting::get(self::KEY);

        return is_array($value) ? $value : null;
    }

    /** Returns the result for this brand (or one with no brand) and forgets it. */
    public static function take(Brand $brand): ?array
    {
        $value = self::peek();
        if ($value === null || ($value['brand_id'] !== null && $value['brand_id'] !== $brand->id)) {
            return null;
        }
        Setting::query()->whereKey(self::KEY)->delete();

        return $value;
    }
}
