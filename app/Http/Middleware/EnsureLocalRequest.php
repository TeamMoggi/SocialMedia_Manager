<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * On the laptop (smm.mode = local) the app answers only to this laptop itself, never to another device on the Wi-Fi.
 *
 * The address typed in the browser must also be this laptop's own (127.0.0.1 or localhost). Otherwise a web page
 * could point its own domain at 127.0.0.1 ("DNS rebinding") and use the automatically signed-in app.
 */
class EnsureLocalRequest
{
    private const LOOPBACK = ['127.0.0.1', '::1'];

    private const LOCAL_HOSTS = ['127.0.0.1', 'localhost', '::1', '[::1]'];

    public function handle(Request $request, Closure $next): Response
    {
        if (config('smm.mode') === 'local') {
            if (! in_array($request->ip(), self::LOOPBACK, true)) {
                abort(403, 'SocialMedia Manager only opens on the laptop it runs on.');
            }
            if (! in_array(strtolower($request->getHost()), self::LOCAL_HOSTS, true)) {
                abort(403, 'Open SocialMedia Manager at http://127.0.0.1 on this laptop.');
            }
        }

        return $next($request);
    }
}
