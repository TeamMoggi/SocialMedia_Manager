<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * On the laptop (smm.mode = local) the app answers only to this laptop itself, never to another device on the Wi-Fi.
 */
class EnsureLocalRequest
{
    private const LOOPBACK = ['127.0.0.1', '::1'];

    public function handle(Request $request, Closure $next): Response
    {
        if (config('smm.mode') === 'local' && ! in_array($request->ip(), self::LOOPBACK, true)) {
            abort(403, 'SocialMedia Manager only opens on the laptop it runs on.');
        }

        return $next($request);
    }
}
