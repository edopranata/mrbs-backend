<?php

namespace App\Providers;

use App\Services\AppSettings;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AppSettings::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Pengaturan dari menu Pengaturan menimpa default config/mrbs.php.
        $this->app->make(AppSettings::class)->apply();
    }
}
