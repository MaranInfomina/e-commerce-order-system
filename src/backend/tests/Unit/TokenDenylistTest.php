<?php

use App\Services\TokenDenylist;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

// Bare keys throughout: phpredis applies the coe_ client prefix at the
// connection level, so prefixing by hand would query coe_coe_denylist:...

it('bounds the entry lifetime by the token remaining lifetime', function () {
    // Without this, changing the TTL to a constant — a year, or no expiry at
    // all — leaves every other test green while the denylist grows forever.
    $jti = (string) Str::uuid();

    app(TokenDenylist::class)->revoke($jti, time() + 30);

    $ttl = Redis::connection('test')->ttl('denylist:'.$jti);

    expect($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(30);
});

it('writes nothing for a token that has already expired', function () {
    // The ttl <= 0 branch is otherwise dead code as far as the suite knows.
    // A setex with a non-positive TTL is an error, not a no-op.
    $jti = (string) Str::uuid();

    app(TokenDenylist::class)->revoke($jti, time() - 1);

    expect(Redis::connection('test')->exists('denylist:'.$jti))->toBe(0)
        ->and(app(TokenDenylist::class)->isRevoked($jti))->toBeFalse();
});

it('keeps the denylist out of the cache and session databases', function () {
    // Revoked tokens live in the carts database, which is never flushed
    // wholesale. In the cache database a routine flush would silently
    // un-revoke every logged-out token.
    $jti = (string) Str::uuid();

    app(TokenDenylist::class)->revoke($jti, time() + 60);

    expect(Redis::connection('test')->exists('denylist:'.$jti))->toBe(1)
        ->and(Redis::connection('cache')->exists('denylist:'.$jti))->toBe(0)
        ->and(Redis::connection('session')->exists('denylist:'.$jti))->toBe(0);
});

it('sends production writes to the carts connection, not the flushable cache', function () {
    // The only assertion in the suite that touches the non-test arm.
    //
    // Asserting the literal index would be wrong here: phpunit.xml forces
    // REDIS_DB=15 so the suite's `default` is the carts test mirror, not
    // production's 1. What must hold in BOTH environments is the separation —
    // the denylist connection is never the one a cache or session flush
    // empties (1/0/2 in production, 15/14/13 under test).
    expect(TokenDenylist::connectionName(false))->toBe('default')
        ->and(TokenDenylist::connectionName(true))->toBe('test')
        ->and(config('database.redis.default.database'))
        ->not->toEqual(config('database.redis.cache.database'))
        ->and(config('database.redis.default.database'))
        ->not->toEqual(config('database.redis.session.database'));
});
