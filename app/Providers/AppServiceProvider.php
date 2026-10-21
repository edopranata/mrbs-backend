<?php

namespace App\Providers;

use App\Services\AppSettings;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

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

        // Token login kedaluwarsa bila tidak dipakai selama mrbs.security.token_idle_days hari.
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $isValid): bool {
            $days = (int) config('mrbs.security.token_idle_days');

            return $isValid && ($days <= 0 || ($token->last_used_at ?? $token->created_at)->gt(now()->subDays($days)));
        });
    }
}
