<?php

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Repositories\CartRepository;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeader;

$tokenFor = function (User $user): string {
    $response = postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'correct-horse-battery',
    ]);

    $response->assertOk();

    return $response->json('token');
};

$orderFor = function (User $user, string $token): int {
    $product = Product::factory()->create(['stock_quantity' => 5]);
    app(CartRepository::class)->setQuantity($user->id, $product->id, 1);

    $response = withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/orders', ['shipping_address' => '221B Baker Street']);

    return $response->json('data.id');
};

it('lists only the caller\'s own orders, newest first', function () use ($tokenFor, $orderFor) {
    $alice = User::factory()->create(['password' => 'correct-horse-battery']);
    $bob = User::factory()->create(['password' => 'correct-horse-battery']);
    $aliceToken = $tokenFor($alice);

    $first = $orderFor($alice, $aliceToken);
    $second = $orderFor($alice, $aliceToken);
    $orderFor($bob, $tokenFor($bob));

    $response = withHeader('Authorization', "Bearer {$aliceToken}")->getJson('/api/v1/orders');

    $response->assertOk()->assertJsonCount(2, 'data');
    expect($response->json('data.0.id'))->toBe($second);
    expect($response->json('data.1.id'))->toBe($first);
});

it('returns 401 for an anonymous order-history request', function () {
    getJson('/api/v1/orders')->assertStatus(401);
});

it('lets a customer view their own order detail', function () use ($tokenFor, $orderFor) {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);
    $token = $tokenFor($user);
    $orderId = $orderFor($user, $token);

    withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/orders/{$orderId}")
        ->assertOk()
        ->assertJsonPath('data.id', $orderId)
        ->assertJsonStructure(['data' => ['items', 'status_history']]);
});

it('returns an identical 404 for another user\'s order and a nonexistent order', function () use ($tokenFor, $orderFor) {
    $alice = User::factory()->create(['password' => 'correct-horse-battery']);
    $bob = User::factory()->create(['password' => 'correct-horse-battery']);
    $bobToken = $tokenFor($bob);
    $orderId = $orderFor($alice, $tokenFor($alice));

    $foreignOrder = withHeader('Authorization', "Bearer {$bobToken}")
        ->getJson("/api/v1/orders/{$orderId}");

    $missingOrder = withHeader('Authorization', "Bearer {$bobToken}")
        ->getJson('/api/v1/orders/999999');

    $foreignOrder->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
    $missingOrder->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
    expect($foreignOrder->json())->toBe($missingOrder->json());
});

it('lets an admin view any order by id', function () use ($tokenFor, $orderFor) {
    $customer = User::factory()->create(['password' => 'correct-horse-battery']);
    $admin = User::factory()->admin()->create(['password' => 'correct-horse-battery']);
    $orderId = $orderFor($customer, $tokenFor($customer));

    withHeader('Authorization', 'Bearer '.$tokenFor($admin))
        ->getJson("/api/v1/orders/{$orderId}")
        ->assertOk()
        ->assertJsonPath('data.id', $orderId);
});

it('keeps an order\'s detail unaffected by a later soft-delete of a referenced product', function () use ($tokenFor, $orderFor) {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);
    $token = $tokenFor($user);
    $product = Product::factory()->create(['stock_quantity' => 5, 'name' => 'Original Name']);
    app(CartRepository::class)->setQuantity($user->id, $product->id, 1);

    $orderId = withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/orders', ['shipping_address' => '221B Baker Street'])
        ->json('data.id');

    $product->delete();

    withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/orders/{$orderId}")
        ->assertOk()
        ->assertJsonPath('data.items.0.product_name', 'Original Name');
});
