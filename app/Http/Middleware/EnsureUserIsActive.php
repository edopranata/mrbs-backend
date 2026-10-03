<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menolak token milik user yang sudah dinonaktifkan admin.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            $user->currentAccessToken()?->delete();
            abort(403, 'Akun Anda telah dinonaktifkan. Hubungi admin.');
        }

        return $next($request);
    }
}
