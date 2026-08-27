<?php

namespace App\Providers;

use App\Services\TokenService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TokenService::class, fn () => new TokenService(
            secret: (string) config('jwt.secret'),
            ttl: (int) config('jwt.ttl'),
            algo: (string) config('jwt.algo'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
