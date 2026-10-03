<?php

use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

// Frontend Vue (public/app/index.html) untuk semua URL halaman. Tanpa middleware "web"
// karena aplikasi memakai token API, sehingga membuka halaman tidak membuat session/cookie.
Route::get('/', SpaController::class)->withoutMiddleware('web');
Route::fallback(SpaController::class)->withoutMiddleware('web');
