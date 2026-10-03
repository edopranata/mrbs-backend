<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Berkas PWA untuk frontend yang disajikan dari public/app.
 */
class PwaController extends Controller
{
    /**
     * Service worker hasil build (public/app/sw.js) disajikan dari /sw.js agar cakupannya
     * seluruh situs. Tidak di-cache supaya browser selalu bisa mendeteksi versi baru.
     */
    public function serviceWorker(): Response
    {
        $path = dirname(config('mrbs.spa_index')).'/sw.js';
        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /**
     * Web App Manifest; nama aplikasi mengikuti menu Pengaturan (System Admin).
     */
    public function manifest(): JsonResponse
    {
        $icon = fn (string $file, string $size, string $purpose) => [
            'src' => "/app/icons/{$file}", 'sizes' => $size, 'type' => 'image/png', 'purpose' => $purpose,
        ];

        return response()->json([
            'name' => config('mrbs.app_name'),
            'short_name' => config('mrbs.app_name'),
            'description' => config('mrbs.app_subtitle'),
            'lang' => 'id',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#f8fafc',
            'theme_color' => '#4f46e5',
            'icons' => [
                $icon('icon-192.png', '192x192', 'any'),
                $icon('icon-512.png', '512x512', 'any'),
                $icon('maskable-512.png', '512x512', 'maskable'),
            ],
        ], 200, [
            'Content-Type' => 'application/manifest+json',
            'Cache-Control' => 'no-cache',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
