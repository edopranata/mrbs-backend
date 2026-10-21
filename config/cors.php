<?php

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
|
| CORS hanya diperiksa oleh browser. Web (public/app) disajikan dari domain yang sama dengan API,
| jadi tidak membutuhkan CORS; aplikasi Android memanggil API langsung (bukan lewat browser) dan
| tidak terpengaruh sama sekali. Daftar ini hanya membatasi situs lain yang mencoba memanggil API
| dari browser pengunjungnya.
|
*/

$origins = array_values(array_filter(array_map(
    fn (string $origin) => rtrim(trim($origin), '/'),
    explode(',', (string) (env('CORS_ALLOWED_ORIGINS') ?: env('APP_URL', ''))),
)));

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With'],

    'exposed_headers' => [],

    'max_age' => 3600,

    // Login memakai token di header Authorization, bukan cookie.
    'supports_credentials' => false,

];
