<?php

use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

it('resolves the cache store to redis, not the file driver', function () {
    // Assert the RESOLVED store object, not the config string.
    // `Cache::getDefaultDriver()` is NOT the resolved driver despite the name
    // — CacheManager returns `config('cache.default')` verbatim, so asserting
    // on it only proves the config says redis, never that redis is what got
    // built. Resolving the store and checking its class is what distinguishes
    // "configured for redis" from "actually using redis", which is the whole
    // defect this task exists to catch. phpunit.xml deliberately does not
    // force CACHE_STORE, so the container's real value reaches this test.
    expect(Cache::store()->getStore())->toBeInstanceOf(RedisStore::class);
});

it('uses the dedicated test cache database, not the development one', function () {
    // Without this the suite silently shares database 0 with the running
    // application: probe keys land in the developer's live cache and the
    // Pest hook flushes it between tests. An <env> entry in phpunit.xml is
    // not enough to move it — phpdotenv reads $_SERVER first — so assert the
    // index the connection actually resolved to.
    expect(config('database.redis.cache.database'))->toBe('14');
    expect(config('database.redis.test.database'))->toBe('15');

    // ...and assert the index the connection ACTUALLY opened, not just the
    // config array. These can disagree: every connection reads REDIS_URL, and
    // ConfigurationUrlParser lets a URL path override `database`, so
    // REDIS_URL=redis://redis:6379/0 leaves the config saying 14 while the
    // live socket sits on development database 0. Asserting only the config
    // key would be the same mistake as asserting Cache::getDefaultDriver().
    expect(Redis::connection('cache')->client()->getDbNum())->toBe(14);
    expect(Redis::connection('test')->client()->getDbNum())->toBe(15);
});

it('writes a cache entry that is genuinely visible in redis with a ttl', function () {
    Cache::put('guard:probe', 'value', 60);

    // Go around Laravel to the raw connection: this proves the bytes are in
    // Redis, not merely that Laravel's own facade round-trips.
    //
    // The `cache` connection, not `test`: the test cache lives on index 14
    // and carts plus the denylist on 15, mirroring the production split.
    //
    // The key is passed BARE. phpredis applies database.redis.options.prefix
    // at the client level, so every command through this connection is
    // already prefixed; prepending it by hand would query coe_coe_guard:probe
    // and ttl() would return -2. CACHE_PREFIX is forced empty, so the cache
    // store contributes no second prefix either.
    $ttl = Redis::connection('cache')->ttl('guard:probe');

    expect($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
});

it('applies the retry and backoff options to every redis connection', function () {
    // config/database.php puts these in `options`, which
    // PhpRedisConnector::connect() merges into every connection. "Merged into
    // the config array" and "set on the client" are different claims though,
    // so read them back off the live phpredis client rather than off config().
    //
    // Without them a single dropped packet is an immediate exception on a
    // user-visible request, now that the cache, sessions, carts and the JWT
    // denylist all live here. With them phpredis retries the transport and
    // still throws once the attempts are exhausted — fail-closed behaviour is
    // preserved, only the window is narrowed.
    foreach (['default', 'cache', 'session', 'test'] as $name) {
        $client = Redis::connection($name)->client();

        expect((int) $client->getOption(\Redis::OPT_MAX_RETRIES))
            ->toBe(3, "{$name} has no retry budget");
        expect((int) $client->getOption(\Redis::OPT_BACKOFF_ALGORITHM))
            ->toBe(\Redis::BACKOFF_ALGORITHM_DECORRELATED_JITTER, "{$name} has the wrong backoff algorithm");
        expect((int) $client->getOption(\Redis::OPT_BACKOFF_BASE))
            ->toBe(100, "{$name} has the wrong backoff base");
        expect((int) $client->getOption(\Redis::OPT_BACKOFF_CAP))
            ->toBe(1000, "{$name} has the wrong backoff cap");
    }
});
