<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Unit tests get the framework too, but deliberately NOT RefreshDatabase:
// a unit test that needs Postgres is a feature test in the wrong directory.
// The reason they need the framework at all is the Redis hook below. Without
// a booted application the `Redis` facade throws "a facade root has not been
// set", so the hook could only run in Unit behind an `if (app())` check —
// and a hook that silently does nothing is precisely the silent-fallback
// shape this suite's guards exist to prevent. Booting the container is the
// cheap, honest alternative.
pest()->extend(TestCase::class)->in('Unit');

// RefreshDatabase rolls back Postgres but does not touch Redis, so without
// this a cart test passes against a previous test's leftovers — the silent
// failure shape this project has been bitten by before. Flushing every test
// database between tests makes each test start from nothing.
//
// Separate databases, not one, and that matters. The production split exists so a
// cache flush cannot clear carts (index 0 = cache, 1 = carts + denylist). If
// the suite pointed both at a single index, that invariant would be inverted
// under test and no test could ever detect a cache flush destroying a cart —
// CartRepository or TokenDenylist wired to the `cache` connection would pass
// the entire suite. Tests mirror the split: 15 = carts + denylist, 14 = cache,
// 13 = sessions (phpunit.xml forces all three).
//
// `Feature` AND `Unit`. Redis is not a feature-test-only dependency: the cart
// repository, the cache and the JWT denylist are plain services that a unit
// test can exercise directly, and Milestone 2's later tasks add exactly such
// tests. Isolating only one directory means the first unit test to touch
// Redis inherits the previous test's keys and passes for the wrong reason.
// tests/Unit/RedisIsolationTest.php fails if this list loses 'Unit'.
pest()->beforeEach(function () {
    Redis::connection('test')->flushdb();
    Redis::connection('cache')->flushdb();
    Redis::connection('session')->flushdb();
})->in('Feature', 'Unit');
