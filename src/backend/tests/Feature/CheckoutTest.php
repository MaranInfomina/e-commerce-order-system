<?php

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Repositories\CartRepository;
use Illuminate\Support\Facades\Bus;

use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeader;
use function Pest\Laravel\withoutToken;

// Same guard as every other feature test file that logs in — a 401/422 here
// yields null and every case fails as 401 pointing at the wrong layer.
$tokenFor = function (User $user): string {
    $response = postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'correct-horse-battery',
    ]);

    $response->assertOk();

    return $response->json('token');
};

$customer = fn (): User => User::factory()->create(['password' => 'correct-horse-battery']);

it('converts a populated cart into an order and clears the cart', function () use ($customer, $tokenFor) {
    // Bus::fake() so this test proves ONLY the synchronous checkout
    // transaction — it must not depend on ProcessPayment/SendOrderConfirmation
    // (implemented fully in a later task) actually doing anything yet.
    Bus::fake();

    $user = $customer();
    $token = $tokenFor($user);
    $product = Product::factory()->create(['price_cents' => 1999, 'stock_quantity' => 10]);
    $carts = app(CartRepository::class);
    $carts->setQuantity($user->id, $product->id, 2);

    $response = withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/orders', ['shipping_address' => '221B Baker Street']);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.total_cents', 3998)
        ->assertJsonPath('data.items.0.product_name', $product->name)
        ->assertJsonPath('data.items.0.quantity', 2)
        ->assertJsonPath('data.items.0.line_total_cents', 3998)
        ->assertJsonPath('data.status_history.0.status', 'pending')
        ->assertJsonPath('data.status_history.0.caused_by', 'system');

    expect($carts->get($user->id))->toBe([]);
    expect(Product::find($product->id)->stock_quantity)->toBe(8);
});

it('rejects checkout with an empty cart', function () use ($customer, $tokenFor) {
    withHeader('Authorization', 'Bearer '.$tokenFor($customer()))
        ->postJson('/api/v1/orders', ['shipping_address' => '221B Baker Street'])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['cart']]]);

    expect(Order::count())->toBe(0);
});

it('requires a shipping address', function () use ($customer, $tokenFor) {
    withHeader('Authorization', 'Bearer '.$tokenFor($customer()))
        ->postJson('/api/v1/orders', [])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['shipping_address']]]);
});

it('accepts an optional note and rejects an unauthenticated checkout', function () use ($customer, $tokenFor) {
    Bus::fake();

    $user = $customer();
    $token = $tokenFor($user);
    $product = Product::factory()->create(['stock_quantity' => 5]);
    app(CartRepository::class)->setQuantity($user->id, $product->id, 1);

    withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/orders', [
            'shipping_address' => '221B Baker Street',
            'notes' => 'Leave with the concierge',
        ])
        ->assertCreated()
        ->assertJsonPath('data.notes', 'Leave with the concierge');

    // withHeader() above set a sticky default Authorization header that
    // would otherwise leak into this call too (Laravel's TestCase keeps
    // defaultHeaders for every subsequent request in the same test) —
    // withoutToken() strips it so this really exercises the no-token path.
    withoutToken()->postJson('/api/v1/orders', ['shipping_address' => '221B Baker Street'])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

it('keeps an order line\'s name, price, and image snapshot unchanged after the product is later edited', function () use ($customer, $tokenFor) {
    Bus::fake();

    $user = $customer();
    $token = $tokenFor($user);
    $product = Product::factory()->create([
        'name' => 'Original Name',
        'price_cents' => 1000,
        'image_path' => 'products/1/original.jpg',
        'stock_quantity' => 10,
    ]);
    app(CartRepository::class)->setQuantity($user->id, $product->id, 1);

    $orderId = withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/orders', ['shipping_address' => '221B Baker Street'])
        ->json('data.id');

    // The live product changes in every snapshotted dimension after checkout.
    $product->update([
        'name' => 'Renamed Product',
        'price_cents' => 500000,
        'image_path' => 'products/1/replacement.jpg',
    ]);

    $item = Order::findOrFail($orderId)->items()->firstOrFail();

    expect($item->product_name)->toBe('Original Name');
    expect($item->unit_price_cents)->toBe(1000);
    expect($item->product_image_path)->toBe('products/1/original.jpg');
    expect($item->image_url)->toContain('products/1/original.jpg');
});

it('returns the original order on a repeated idempotency key instead of creating a second one', function () use ($customer, $tokenFor) {
    $user = $customer();
    $token = $tokenFor($user);
    $product = Product::factory()->create(['stock_quantity' => 10]);
    $carts = app(CartRepository::class);
    $carts->setQuantity($user->id, $product->id, 1);

    $first = withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Idempotency-Key', 'retry-key-1')
        ->postJson('/api/v1/orders', ['shipping_address' => '221B Baker Street']);

    $first->assertCreated();
    $orderId = $first->json('data.id');

    // Re-add the same product: a real client would not do this, but it
    // proves the SECOND request never re-runs the checkout transaction at
    // all, rather than merely happening to decrement the same way twice.
    $carts->setQuantity($user->id, $product->id, 1);

    $second = withHeader('Authorization', "Bearer {$token}")
        ->withHeader('Idempotency-Key', 'retry-key-1')
        ->postJson('/api/v1/orders', ['shipping_address' => '221B Baker Street']);

    $second->assertOk()->assertJsonPath('data.id', $orderId);

    expect(Order::count())->toBe(1);
    expect(Order::first()->items()->count())->toBe(1);
});

it('scopes an idempotency key per user, never colliding across users', function () use ($customer, $tokenFor) {
    $alice = $customer();
    $bob = $customer();
    $aliceToken = $tokenFor($alice);
    $bobToken = $tokenFor($bob);
    $carts = app(CartRepository::class);

    foreach ([$alice, $bob] as $user) {
        $product = Product::factory()->create(['stock_quantity' => 5]);
        $carts->setQuantity($user->id, $product->id, 1);
    }

    withHeader('Authorization', "Bearer {$aliceToken}")
        ->withHeader('Idempotency-Key', 'shared-value')
        ->postJson('/api/v1/orders', ['shipping_address' => '221B Baker Street'])
        ->assertCreated();

    withHeader('Authorization', "Bearer {$bobToken}")
        ->withHeader('Idempotency-Key', 'shared-value')
        ->postJson('/api/v1/orders', ['shipping_address' => '10 Downing Street'])
        ->assertCreated();

    expect(Order::count())->toBe(2);
});
