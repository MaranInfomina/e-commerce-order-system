<?php

use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/*
| tests/Pest.php flushes the Redis test databases before every test. That hook
| was `->in('Feature')` only, which left any unit test touching the cart, the
| cache or the JWT denylist running against the previous test's keys. These
| two tests are the tripwire for that: the first deliberately leaves keys
| behind, the second asserts they are gone. Drop 'Unit' from either the
| `pest()->extend()` call or the `beforeEach` hook in tests/Pest.php and this
| file fails — with a facade-root error or a leaked key respectively.
|
| Pest runs the tests in a file in declaration order, so the pair is stable.
*/

$connections = ['test', 'cache', 'session'];

it('boots the framework for unit tests so the redis hook can run at all', function () {
    expect($this)->toBeInstanceOf(TestCase::class);
});

it('leaves a key behind in every redis test database', function () use ($connections) {
    foreach ($connections as $connection) {
        Redis::connection($connection)->set('isolation:leak', '1');

        expect(Redis::connection($connection)->exists('isolation:leak'))->toBe(1);
    }
});

it('starts the next unit test with those databases already flushed', function () use ($connections) {
    foreach ($connections as $connection) {
        expect(Redis::connection($connection)->exists('isolation:leak'))
            ->toBe(0, "{$connection} still holds the previous unit test's key");
    }
});
