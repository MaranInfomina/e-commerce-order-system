<?php

use App\Models\Product;
use App\Models\User;
use App\Repositories\CartRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeader;

it('allows exactly one of two simultaneous checkouts to claim the last unit of stock', function () {
    if (! function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl extension is not available.');
    }

    $user = User::factory()->create(['password' => 'correct-horse-battery']);
    $product = Product::factory()->create(['stock_quantity' => 1]);
    app(CartRepository::class)->setQuantity($user->id, $product->id, 1);
    $token = postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct-horse-battery'])->json('token');

    // RefreshDatabase wraps this whole test in one open transaction on
    // Laravel's shared connection, so nothing created above is visible to any
    // OTHER connection yet — and the two forked children below must open
    // their own connections to race for real against Postgres's actual row
    // lock, not merely against each other's PHP memory. Commit for real here;
    // the rows committed are cleaned up by hand at the end of this test
    // instead of relying on RefreshDatabase's rollback.
    DB::commit();

    // Fully close the connection before forking, rather than merely leaving
    // it open. pcntl_fork() duplicates file descriptors, not sockets — a
    // live PDO connection at fork time means the parent and both children
    // share the exact same underlying TCP stream to Postgres. The first side
    // to tear its (identical) connection down — a child calling DB::purge(),
    // or even just a child process exiting and PHP destructing its inherited
    // PDO object — sends a real wire-level Postgres Terminate message down
    // that shared socket, which kills the connection for every other holder
    // of the same fd, the still-running parent included ("server closed the
    // connection unexpectedly" on whatever query the parent runs next).
    // Disconnecting here means there is nothing live left to inherit: each
    // process dials its own independent connection the next time it queries.
    DB::purge();

    // The same fork hazard applies to the cart's Redis connection: the
    // setQuantity() call above (and the earlier login) already opened one,
    // and CartRepository::get() is the very first thing OrderController hits
    // in each child. Two children issuing HGETALL over one shared,
    // inherited phpredis socket at the same moment corrupts the RESP wire
    // protocol — observed here as hgetall silently returning false instead
    // of an array, which CartRepository::get()'s foreach then blows up on.
    //
    // Redis::purge($name) defaults $name to the literal string 'default'
    // when none is given — NOT to whatever connection is actually in use.
    // CartRepository (and TokenDenylist, on the same logical database) talk
    // to the connection named 'test' under the suite, so an argument-less
    // purge() silently purges nothing this test touches, leaving the real
    // connection shared across the fork regardless. Naming it explicitly
    // (via the repository's own connection-name resolver, so this can never
    // drift out of sync with what CartRepository actually uses) is what
    // makes this purge do anything at all.
    Redis::purge(CartRepository::connectionName(app()->runningUnitTests()));

    $resultsFile = tempnam(sys_get_temp_dir(), 'coe-race-');
    $pids = [];

    for ($i = 0; $i < 2; $i++) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            test()->fail('pcntl_fork failed.');
        }

        if ($pid === 0) {
            // The parent already disconnected both connections before
            // forking, so there is nothing live to purge here — these calls
            // are harmless no-ops that keep the child robust even if that
            // ever changes. Each child's first query/command below lazily
            // opens its own brand-new connection, independent of its
            // sibling and of the parent.
            DB::purge();
            Redis::purge(CartRepository::connectionName(app()->runningUnitTests()));

            $status = withHeader('Authorization', "Bearer {$token}")
                ->postJson('/api/v1/orders', ['shipping_address' => '221B Baker Street'])
                ->getStatusCode();

            file_put_contents($resultsFile, $status."\n", FILE_APPEND | LOCK_EX);

            exit(0);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $exitStatus);
    }

    $statuses = array_values(array_filter(explode("\n", file_get_contents($resultsFile))));
    unlink($resultsFile);
    sort($statuses);

    // Exactly one 201 and one clean 422 — never two 201s (an oversell) and
    // never a 500 (the CHECK constraint firing as a raw, unhandled error
    // instead of the application-level stock check catching it first).
    expect($statuses)->toBe(['201', '422']);
    expect(Product::find($product->id)->stock_quantity)->toBe(0);

    // Manual cleanup: this data was committed for real above, so
    // RefreshDatabase's rollback at teardown will not remove it. This MUST
    // run outside any transaction: these deletes need to be committed
    // immediately (the default with no transaction open), because the next
    // statement after this opens one purely for teardown's benefit — a
    // transaction that RefreshDatabase then rolls back. Doing the cleanup
    // AFTER that beginTransaction() would silently undo the cleanup itself
    // (proven the hard way: the row survived into the next test file and
    // failed its `Order::count() === 0` assertion), leaving this test
    // "green" while quietly leaking rows into every test that runs after it.
    $orderIds = DB::table('orders')->where('user_id', $user->id)->pluck('id');
    DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
    DB::table('order_status_history')->whereIn('order_id', $orderIds)->delete();
    DB::table('orders')->where('user_id', $user->id)->delete();
    DB::table('products')->where('id', $product->id)->delete();
    DB::table('users')->where('id', $user->id)->delete();

    // Only now, with cleanup already committed for real, open a transaction
    // purely so RefreshDatabase's teardown (a ROLLBACK) has something to
    // roll back without erroring — this connection is not shared with
    // anyone else at this point (both children have long since exited).
    DB::beginTransaction();
});
