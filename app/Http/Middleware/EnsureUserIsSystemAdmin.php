<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsSystemAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isSystemAdmin(), 403, 'Hanya System Admin yang dapat mengakses fitur ini.');

        return $next($request);
    }
}
