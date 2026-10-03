<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menyajikan frontend Vue (hasil `npm run build:laravel` di public/app) untuk semua
 * URL halaman, sehingga deep link seperti /jadwal atau /admin/users tetap terbuka
 * walau di-refresh. Routing halaman selanjutnya ditangani Vue Router.
 */
class SpaController extends Controller
{
    public function __invoke(Request $request): Response
    {
        // URL API yang tidak dikenal tetap 404 JSON, bukan halaman aplikasi.
        abort_if($request->is('api', 'api/*'), 404);

        $index = config('mrbs.spa_index');

        if (! is_file($index)) {
            return response(
                'Frontend belum di-build. Jalankan `npm run build:laravel` di folder frontend.',
                503,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            );
        }

        return response()->file($index, [
            'Content-Type' => 'text/html; charset=UTF-8',
            // index.html selalu dicek ulang agar versi terbaru langsung terpakai setelah deploy.
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }
}
