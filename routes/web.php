<?php

use App\Http\Controllers\PwaController;
use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

// Tanpa middleware "web" karena aplikasi memakai token API, sehingga membuka halaman
// tidak membuat session/cookie.

// PWA: service worker & manifest (lihat juga aturan di public/.htaccess).
Route::get('/sw.js', [PwaController::class, 'serviceWorker'])->withoutMiddleware('web');
Route::get('/manifest.webmanifest', [PwaController::class, 'manifest'])->withoutMiddleware('web');

// Frontend Vue (public/app/index.html) untuk semua URL halaman.
Route::get('/', SpaController::class)->withoutMiddleware('web');
Route::fallback(SpaController::class)->withoutMiddleware('web');
