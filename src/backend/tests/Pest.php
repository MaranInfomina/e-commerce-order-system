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

// RefreshDatabase rolls back Postgres but does not touch Redis, so without
// this a cart test passes against a previous test's leftovers — the silent
// failure shape this project has been bitten by before. Flushing both test
// databases between tests makes each test start from nothing.
//
// Two databases, not one, and that matters. The production split exists so a
// cache flush cannot clear carts (index 0 = cache, 1 = carts + denylist). If
// the suite pointed both at a single index, that invariant would be inverted
// under test and no test could ever detect a cache flush destroying a cart —
// CartRepository or TokenDenylist wired to the `cache` connection would pass
// the entire suite. Tests mirror the split: 15 = carts + denylist, 14 = cache.
pest()->beforeEach(function () {
    Redis::connection('test')->flushdb();
    Redis::connection('cache')->flushdb();
})->in('Feature');
