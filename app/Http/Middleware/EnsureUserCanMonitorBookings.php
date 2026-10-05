<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pantauan Semua Booking: Admin, System Admin, dan View Only.
 */
class EnsureUserCanMonitorBookings
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->canMonitorBookings(), 403, 'Anda tidak memiliki akses ke halaman ini.');

        return $next($request);
    }
}
