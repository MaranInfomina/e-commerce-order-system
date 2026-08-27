<?php

use Illuminate\Cache\RedisStore;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;

/*
| The cache half of this milestone's Redis switch has RedisGuardTest. The
| session half had nothing: docker-compose.yml set SESSION_DRIVER=redis and
| phpunit.xml forced it back to `array`, so deleting the compose variable
| would have left the whole suite green while every real request fell back to
| the file driver. phpunit.xml no longer forces it — see the comment there —
| which is what lets these three tests mean anything.
*/

it('resolves the session handler to redis, not the file or array driver', function () {
    // The RESOLVED handler, not config('session.driver'). SessionManager
    // returns the config string verbatim from getDefaultDriver(), so
    // asserting on that only proves the config says redis, never that redis
    // is what got built — the same distinction RedisGuardTest draws for the
    // cache store.
    $handler = Session::getHandler();

    expect($handler)->toBeInstanceOf(CacheBasedSessionHandler::class);
    expect($handler->getCache()->getStore())->toBeInstanceOf(RedisStore::class);
});

it('keeps sessions out of the carts and JWT denylist database', function () {
    // config/database.php documents the `default` connection as "carts and
    // the JWT denylist. Never flushed wholesale." A redis session driver with
    // no session.connection lands there. Assert both the wiring and the two
    // sockets it actually opened, because the config array and the live
    // connection can disagree (REDIS_URL's path overrides `database`).
    expect(config('session.connection'))->toBe('session');
    expect(Session::getHandler()->getCache()->getStore()->connection()->getName())
        ->toBe('session');

    $sessionIndex = Redis::connection('session')->client()->getDbNum();
    $cartIndex = Redis::connection('default')->client()->getDbNum();

    expect($sessionIndex)->toBe(13);
    expect($cartIndex)->toBe(15);
    expect($sessionIndex)->not->toBe($cartIndex);
});

it('writes a real request session into the session database and nowhere else', function () {
    // A real request through StartSession, so the session is opened, written
    // and persisted by the same middleware that does it in production.
    // Poking Session::put() directly would skip the middleware that is the
    // actual subject here.
    //
    // StartSession alone rather than the whole `web` group on purpose: the
    // group also pulls in EncryptCookies, and `docker compose exec` hands the
    // test process the container's *configured* APP_KEY, which is empty —
    // the entrypoint's ephemeral key only ever exists inside php-fpm's own
    // process. Depending on it would make this test fail for a reason that
    // has nothing to do with sessions.
    Route::middleware(StartSession::class)->get('/__session-driver-probe', function () {
        session()->put('probe', 'value');

        return response('ok');
    });

    // Every test starts from a flushed Redis (tests/Pest.php), so any key
    // found afterwards was written by this request.
    expect(Redis::connection('session')->dbsize())->toBe(0);

    $this->get('/__session-driver-probe')->assertOk();

    expect(Redis::connection('session')->dbsize())->toBeGreaterThan(0);

    // ...and the carts + denylist database is still untouched. This is the
    // assertion that fails if session.connection ever falls back to
    // `default`, which is the defect this test exists for.
    expect(Redis::connection('default')->dbsize())->toBe(0);
});
