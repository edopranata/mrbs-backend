<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header keamanan untuk semua respons dari Laravel. File statis frontend (public/app) yang
 * disajikan langsung oleh web server mendapat header yang sama dari public/.htaccess.
 */
class SecurityHeaders
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        // Aplikasi tidak boleh dimuat di dalam iframe situs lain (clickjacking).
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        // Browser hanya memakai HTTPS untuk domain ini selama setahun ke depan.
        if ($request->isSecure() || app()->isProduction()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        // Jangan umumkan versi PHP.
        $headers->remove('X-Powered-By');
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        return $response;
    }
}
