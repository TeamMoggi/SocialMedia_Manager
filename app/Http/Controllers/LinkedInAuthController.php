<?php

namespace App\Http\Controllers;

use App\Domain\LinkedIn\LinkedInApi;
use App\Domain\LinkedIn\LinkedInConnector;
use App\Domain\LinkedIn\LinkedInCredentials;
use App\Domain\LinkedIn\SignInResult;
use App\Models\Brand;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The LinkedIn sign-in round trip. It runs entirely on http://localhost:{port} (the address saved in
 * the LinkedIn developer portal), so the session that holds the one-time state is still there when
 * LinkedIn sends the owner back. The owner then returns to the app's usual http://127.0.0.1 address.
 */
class LinkedInAuthController extends Controller
{
    private const EXPIRED = 'This sign-in link has expired. Press Connect LinkedIn again.';

    /** Where the Connect button points: the start of the sign-in, on localhost. */
    public static function startUrl(Brand $brand, int $port): string
    {
        return "http://localhost:{$port}/{$brand->slug}/linkedin/start";
    }

    public static function callbackUrl(int $port): string
    {
        return "http://localhost:{$port}/linkedin/callback";
    }

    public function start(Request $request, LinkedInApi $api): RedirectResponse
    {
        /** @var Brand $brand */
        $brand = Filament::getTenant();
        $port = (int) $request->getPort();

        if (LinkedInCredentials::clientSecret() === null) {
            SignInResult::put($brand->id, false, 'Save the Client Secret first (Save app details).', null);

            return redirect()->away(self::pageUrl($brand, $port));
        }

        $state = Str::random(48);
        $request->session()->put('linkedin.oauth', ['state' => $state, 'brand_id' => $brand->id, 'port' => $port, 'at' => now()->getTimestamp()]);

        return redirect()->away($api->authorizeUrl(self::callbackUrl($port), $state, LinkedInConnector::SCOPES));
    }

    public function callback(Request $request, LinkedInConnector $connector): RedirectResponse
    {
        // Pulled first, so the sign-in can be used only once whatever happens next.
        $saved = $request->session()->pull('linkedin.oauth');
        $brand = is_array($saved) ? Brand::find($saved['brand_id'] ?? null) : null;
        $port = is_array($saved) ? (int) $saved['port'] : (int) $request->getPort();

        $fresh = $brand !== null
            && is_string($request->query('state'))
            && hash_equals((string) $saved['state'], $request->query('state'))
            && now()->getTimestamp() - (int) $saved['at'] <= 60 * (int) config('smm.linkedin.state_minutes');
        if (! $fresh) {
            SignInResult::put($brand?->id, false, self::EXPIRED, null);

            return redirect()->away($brand !== null ? self::pageUrl($brand, $port) : "http://127.0.0.1:{$port}/");
        }

        if ($request->filled('error')) {
            $cancelled = in_array($request->query('error'), ['user_cancelled_login', 'user_cancelled_authorize'], true);
            SignInResult::put($brand->id, false, $cancelled ? 'You cancelled on LinkedIn. Nothing changed.' : 'LinkedIn did not complete the sign-in. Nothing changed.',
                $cancelled ? null : Str::limit((string) $request->query('error_description', $request->query('error')), 300));

            return redirect()->away(self::pageUrl($brand, $port));
        }

        $result = $connector->connect($brand, (string) $request->query('code', ''), self::callbackUrl($port));
        if ($result->problems !== []) {
            SignInResult::put($brand->id, false, 'Not connected', implode("\n", $result->problems));
        } else {
            $until = $result->account->token_expires_at?->setTimezone($brand->timezone ?? (string) config('smm.display_timezone'))->format('j M Y');
            SignInResult::put($brand->id, $result->warnings === [], 'Connected: '.$result->account->name.($until ? ", key valid until {$until}" : ''),
                $result->warnings !== [] ? implode("\n", $result->warnings) : null);
        }

        return redirect()->away(self::pageUrl($brand, $port));
    }

    private static function pageUrl(Brand $brand, int $port): string
    {
        return "http://127.0.0.1:{$port}/{$brand->slug}/linkedin";
    }
}
