<?php

namespace App\Providers;

use App\Services\TokenService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        // One bucket per endpoint, not one shared between them.
        //
        // `throttle:5,1` builds its key from the route's domain and the
        // client IP and nothing else, so register and login landed in the
        // same counter: five failed logins left a visitor unable to create
        // an account for a minute, and five registrations locked the same
        // visitor out of logging in. Naming the limiters puts the name into
        // the key (ThrottleRequests hashes limiter-name + limit key), which
        // separates them.
        //
        // Still per-IP, deliberately. Keying on the submitted email instead
        // would let an attacker spray one guess across thousands of accounts
        // untouched, and would hand anyone a way to lock a known victim out
        // of their own account by burning their bucket.
        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
    }
}
