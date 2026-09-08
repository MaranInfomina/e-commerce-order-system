<?php

namespace App\Providers;

use App\Auth\JwtGuard;
use App\Models\User;
use App\Services\TokenDenylist;
use App\Services\TokenService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
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
        // The `api` guard's driver. Resolving the guard lazily keeps
        // TokenService out of unauthenticated request paths, and the
        // container refresh below hands the guard each new request.
        //
        // `$app->refresh('request', ...)` is not optional. AuthManager
        // caches the guard for the lifetime of the container, and a test
        // (or an Octane worker) serves several requests through one
        // container. Without the refresh the guard keeps the very first
        // request object and the user it resolved from it, so a token
        // revoked mid-test still authenticates — exactly the finding the
        // denylist tests exist to catch. setRequest() also clears the
        // memoised user so each request re-runs the denylist lookup.
        Auth::extend('jwt', function ($app) {
            $guard = new JwtGuard(
                $app->make(TokenService::class),
                $app->make(TokenDenylist::class),
                $app->make(Request::class),
            );

            $app->refresh('request', $guard, 'setRequest');

            return $guard;
        });

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

        // `artisan queue:work` persists a failed job into failed_jobs itself
        // (Illuminate\Queue\Console\WorkCommand::logFailedJob(), registered
        // only while that command is running) — the queue-worker container's
        // real `queue:work rabbitmq` process already gets this for free, so
        // this listener would double-insert if it ever fired there too.
        // The one connection that never runs through a Worker at all, and so
        // never gets that bookkeeping, is `sync`: phpunit.xml forces
        // QUEUE_CONNECTION=sync for the whole suite specifically so
        // ProcessPayment/SendOrderConfirmation run inline and
        // deterministically, and Illuminate\Queue\SyncQueue::handleException()
        // calls $job->fail($e) — which raises this same JobFailed event —
        // with no Worker anywhere in the call stack to log it. Scoping to
        // `sync`, which production never uses, is what makes this a no-op
        // everywhere a real queue is in play and closes the gap only where
        // it actually exists.
        Queue::failing(function (JobFailed $event): void {
            if ($event->connectionName === 'sync') {
                app('queue.failer')->log(
                    $event->connectionName,
                    $event->job->getQueue(),
                    $event->job->getRawBody(),
                    $event->exception,
                );
            }
        });

        // Queue job-lifecycle metrics, fed to the same Redis-backed
        // CollectorRegistry the HTTP metrics middleware writes to, so
        // php-fpm workers and this app's separate queue-worker container
        // both contribute to the same coe_queue_jobs_* counters.
        Event::listen(function (\Illuminate\Queue\Events\JobProcessed $event) {
            \App\Http\Middleware\RecordHttpMetrics::registry()->getOrRegisterCounter(
                'coe', 'queue_jobs_processed_total', 'Total queue jobs processed successfully',
                ['queue', 'job'],
            )->inc([$event->job->getQueue(), $event->job->resolveName()]);
        });

        Event::listen(function (\Illuminate\Queue\Events\JobFailed $event) {
            \App\Http\Middleware\RecordHttpMetrics::registry()->getOrRegisterCounter(
                'coe', 'queue_jobs_failed_total', 'Total queue jobs that failed permanently',
                ['queue', 'job'],
            )->inc([$event->job->getQueue(), $event->job->resolveName()]);
        });

        // Admin-only, not tied to a specific model — a Gate rather than a
        // policy method, since this isn't scoped to any one Eloquent record.
        Gate::define('viewSalesReport', fn (User $user) => $user->isAdmin());
    }
}
