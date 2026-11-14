<?php

namespace App\Http\Middleware;

use App\Domain\System\IdleWatch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notes that someone is using the app, so smm:serve keeps it running. The /up health check
 * (used by start.ps1 to see whether the app runs) does not count as use.
 */
class RecordActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('up')) {
            IdleWatch::touch();
        }

        return $next($request);
    }
}
