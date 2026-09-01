<?php

use App\Models\Product;
use App\Models\User;
use App\Repositories\CartRepository;
use Illuminate\Support\Facades\Redis;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeader;

// Assert the login worked before handing back its token — the same guard
// Task 6 added to ProductAuthorizationTest and ProductWriteTest. Without it a
// 401 or 422 here yields null, the header becomes a bare "Bearer ", and every
// case fails as 401 with a diagnosis pointing at the cart rather than at the
// login.
$tokenFor = function (User $user): string {
    $response = postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'correct-horse-battery',
    ]);

    $response->assertOk();

    return $response->json('token');
};

$customer = fn (): User => User::factory()->create(['password' => 'correct-horse-battery']);

it('requires authentication on every cart route', function () {
    // "Every" means all five. Checking three of them leaves the two per-item
    // mutation routes unguarded by any test, so one accidentally registered
    // outside the auth:api group would let an anonymous caller change another
    // person's cart while the test asserting otherwise stays green.
    $product = Product::factory()->create();

    getJson('/api/v1/cart')->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
    postJson('/api/v1/cart/items', [])->assertStatus(401);
    patchJson("/api/v1/cart/items/{$product->id}", ['quantity' => 2])->assertStatus(401);
    deleteJson("/api/v1/cart/items/{$product->id}")->assertStatus(401);
    deleteJson('/api/v1/cart')->assertStatus(401);
});

it('starts empty', function () use ($customer, $tokenFor) {
    withHeader('Authorization', 'Bearer '.$tokenFor($customer()))
        ->getJson('/api/v1/cart')
        ->assertOk()
        ->assertJsonPath('data.items', [])
        ->assertJsonPath('data.total_cents', 0)
        ->assertJsonPath('data.item_count', 0);
});

it('adds a product and computes integer line totals', function () use ($customer, $tokenFor) {
    $product = Product::factory()->create(['price_cents' => 1999]);
    $token = $tokenFor($customer());

    withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 2])
        ->assertCreated()
        ->assertJsonPath('data.items.0.product.id', $product->id)
        ->assertJsonPath('data.items.0.quantity', 2)
        ->assertJsonPath('data.items.0.line_total_cents', 3998)
        ->assertJsonPath('data.total_cents', 3998)
        ->assertJsonPath('data.item_count', 2);
});

it('increments rather than duplicating when the same product is added twice', function () use ($customer, $tokenFor) {
    $product = Product::factory()->create(['price_cents' => 500]);
    $token = $tokenFor($customer());

    withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1]);

    withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 3])
        ->assertCreated()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.quantity', 4);
});

it('sets an exact quantity on update rather than adding to it', function () use ($customer, $tokenFor) {
    $product = Product::factory()->create(['price_cents' => 500]);
    $token = $tokenFor($customer());

    withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 5]);

    withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/cart/items/{$product->id}", ['quantity' => 2])
        ->assertOk()
        ->assertJsonPath('data.items.0.quantity', 2);
});

it('removes a line and clears the whole cart', function () use ($customer, $tokenFor) {
    $first = Product::factory()->create();
    $second = Product::factory()->create();
    $token = $tokenFor($customer());

    foreach ([$first, $second] as $product) {
        withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1]);
    }

    withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/cart/items/{$first->id}")
        ->assertNoContent();

    withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/cart')->assertJsonCount(1, 'data.items');

    withHeader('Authorization', "Bearer {$token}")
        ->deleteJson('/api/v1/cart')->assertNoContent();

    withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/cart')->assertJsonPath('data.items', []);
});

it('persists across requests in redis, visible outside the application', function () use ($customer, $tokenFor) {
    $user = $customer();
    $product = Product::factory()->create();
    $token = $tokenFor($user);

    withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 3]);

    // Read the raw hash, not the API, so this proves the bytes are in Redis
    // rather than that the endpoint round-trips its own memory.
    // Bare key: the connection already applies the client-level prefix.
    $hash = Redis::connection('test')->hgetall("cart:user:{$user->id}");

    expect($hash)->toBe([(string) $product->id => '3']);
});

it('sums the total and the item count across more than one line', function () use ($customer, $tokenFor) {
    // Every other total assertion in this file runs against a cart with zero
    // or one line, where `$total += $line` and `$total = $line` produce the
    // same number. Downgrading both accumulators in CartResource would leave
    // the whole file green while every real multi-line cart under-reported
    // its total to the last line only. Two lines with distinct prices and
    // quantities is the smallest cart in which an accumulator is one.
    $first = Product::factory()->create(['price_cents' => 1000]);
    $second = Product::factory()->create(['price_cents' => 250]);
    $token = $tokenFor($customer());

    withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/cart/items', ['product_id' => $first->id, 'quantity' => 2]);

    withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/cart/items', ['product_id' => $second->id, 'quantity' => 3])
        ->assertCreated()
        ->assertJsonCount(2, 'data.items')
        // 2*1000 + 3*250. Neither line total equals the sum, so a
        // last-write-wins bug cannot coincide with the right answer.
        ->assertJsonPath('data.total_cents', 2750)
        ->assertJsonPath('data.item_count', 5);
});

it('sends production cart writes to the carts connection, not the flushable cache', function () use ($customer, $tokenFor) {
    // phpunit.xml forces REDIS_DB=15, so `default` and `test` are the same
    // index under the suite and no feature assertion can tell them apart.
    // Assert the separation itself, which must hold in both environments
    // (1/0/2 in production, 15/14/13 under test). Mirrors
    // tests/Unit/TokenDenylistTest.php, which guards the same seam.
    $user = $customer();
    $product = Product::factory()->create();

    withHeader('Authorization', 'Bearer '.$tokenFor($user))
        ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1]);

    expect(CartRepository::connectionName(false))->toBe('default')
        ->and(CartRepository::connectionName(true))->toBe('test')
        ->and(Redis::connection('test')->exists("cart:user:{$user->id}"))->toBe(1)
        ->and(Redis::connection('cache')->exists("cart:user:{$user->id}"))->toBe(0)
        ->and(Redis::connection('session')->exists("cart:user:{$user->id}"))->toBe(0);
});

it('keeps one user cart separate from another', function () use ($customer, $tokenFor) {
    $alice = $customer();
    $bob = $customer();
    $product = Product::factory()->create();

    withHeader('Authorization', 'Bearer '.$tokenFor($alice))
        ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 7]);

    withHeader('Authorization', 'Bearer '.$tokenFor($bob))
        ->getJson('/api/v1/cart')
        ->assertJsonPath('data.items', []);

    // Then re-read Alice's. Asserting only that Bob's cart is empty proves
    // isolation in one direction: a repository that keyed writes correctly but
    // cleared the whole namespace on another user's read would pass the check
    // above and lose Alice's seven units silently.
    withHeader('Authorization', 'Bearer '.$tokenFor($alice))
        ->getJson('/api/v1/cart')
        ->assertOk()
        ->assertJsonPath('data.items.0.product.id', $product->id)
        ->assertJsonPath('data.items.0.quantity', 7);
});

it('rejects a quantity below one and a product that does not exist', function () use ($customer, $tokenFor) {
    $token = $tokenFor($customer());
    $product = Product::factory()->create();

    withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 0])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['quantity']]]);

    withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/cart/items', ['product_id' => 999999, 'quantity' => 1])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['product_id']]]);
});

it('accepts a quantity of exactly one', function () use ($customer, $tokenFor) {
    // The other side of the boundary above: without this, a rule of
    // `min:2` would pass the rejection test.
    $product = Product::factory()->create();

    withHeader('Authorization', 'Bearer '.$tokenFor($customer()))
        ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1])
        ->assertCreated()
        ->assertJsonPath('data.items.0.quantity', 1);
});

it('drops a line whose product has been soft-deleted since it was added', function () use ($customer, $tokenFor) {
    $product = Product::factory()->create();
    $user = $customer();
    $token = $tokenFor($user);

    withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 2]);

    $product->delete();

    withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/cart')
        ->assertOk()
        ->assertJsonPath('data.items', [])
        ->assertJsonPath('data.total_cents', 0);

    // And the stale field is gone from the hash, not merely hidden.
    expect(Redis::connection('test')->hgetall("cart:user:{$user->id}"))->toBe([]);
});
