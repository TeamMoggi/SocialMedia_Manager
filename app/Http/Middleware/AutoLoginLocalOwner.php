<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * On the laptop there is no login screen: the owner is signed in automatically.
 * When the app is hosted (smm.mode = hosted) this does nothing and the normal login applies.
 */
class AutoLoginLocalOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('smm.mode') === 'local' && ! Auth::check()) {
            Auth::login(User::localOwner());
        }

        return $next($request);
    }
}
