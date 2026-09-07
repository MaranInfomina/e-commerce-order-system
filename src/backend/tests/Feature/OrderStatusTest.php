<?php

use App\Jobs\AdvanceOrderToDelivered;
use App\Jobs\AdvanceOrderToShipped;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Repositories\CartRepository;
use App\Services\OrderStatusTransitioner;

use function Pest\Laravel\flushHeaders;
use function Pest\Laravel\patchJson;
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

$paidOrder = function (): Order {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);
    $token = postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct-horse-battery'])->json('token');
    $product = Product::factory()->create(['stock_quantity' => 5]);
    app(CartRepository::class)->setQuantity($user->id, $product->id, 1);

    $response = withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/orders', ['shipping_address' => '221B Baker Street']);

    return Order::findOrFail($response->json('data.id'));
};

it('advances a paid order all the way to delivered automatically', function () use ($paidOrder) {
    $order = $paidOrder();

    expect($order->status)->toBe(Order::STATUS_PAID);

    // SendOrderConfirmation already dispatched AdvanceOrderToShipped with a
    // 30s delay during checkout; under the sync connection a delayed
    // dispatch still runs immediately when invoked directly like this, so
    // drive the chain explicitly rather than waiting out a real delay.
    AdvanceOrderToShipped::dispatchSync($order->id);
    expect($order->fresh()->status)->toBe(Order::STATUS_SHIPPED);

    AdvanceOrderToDelivered::dispatchSync($order->id);
    expect($order->fresh()->status)->toBe(Order::STATUS_DELIVERED);
});

it('lets an admin advance a paid order to shipped', function () use ($paidOrder, $tokenFor) {
    $order = $paidOrder();
    $admin = User::factory()->admin()->create(['password' => 'correct-horse-battery']);

    withHeader('Authorization', 'Bearer '.$tokenFor($admin))
        ->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'shipped'])
        ->assertOk()
        ->assertJsonPath('data.status', 'shipped');

    expect($order->fresh()->statusHistory()->pluck('status')->all())->toBe(['pending', 'paid', 'shipped']);
});

it('treats a redundant transition as a no-op with no duplicate history row, in either order', function () use ($paidOrder, $tokenFor) {
    $order = $paidOrder();
    $admin = User::factory()->admin()->create(['password' => 'correct-horse-battery']);
    $adminToken = $tokenFor($admin);

    // Admin first, then the automatic path attempts the same transition.
    withHeader('Authorization', "Bearer {$adminToken}")
        ->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'shipped'])
        ->assertOk();

    AdvanceOrderToShipped::dispatchSync($order->id);

    expect($order->fresh()->status)->toBe(Order::STATUS_SHIPPED);
    expect($order->fresh()->statusHistory()->where('status', 'shipped')->count())->toBe(1);

    // Reverse order: a second order, automatic first, admin second.
    $order2 = $paidOrder();
    AdvanceOrderToShipped::dispatchSync($order2->id);

    withHeader('Authorization', "Bearer {$adminToken}")
        ->patchJson("/api/v1/orders/{$order2->id}/status", ['status' => 'shipped'])
        ->assertStatus(422);

    expect($order2->fresh()->status)->toBe(Order::STATUS_SHIPPED);
    expect($order2->fresh()->statusHistory()->where('status', 'shipped')->count())->toBe(1);
});

it('rejects an out-of-sequence transition', function () use ($tokenFor) {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);
    $token = postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct-horse-battery'])->json('token');
    $product = Product::factory()->create(['stock_quantity' => 5]);
    app(CartRepository::class)->setQuantity($user->id, $product->id, 1);

    // FAIL_PAYMENT keeps this order at pending -> payment_failed, never paid.
    $orderId = withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/orders', ['shipping_address' => 'FAIL_PAYMENT 221B Baker Street'])
        ->json('data.id');

    $admin = User::factory()->admin()->create(['password' => 'correct-horse-battery']);

    withHeader('Authorization', 'Bearer '.$tokenFor($admin))
        ->patchJson("/api/v1/orders/{$orderId}/status", ['status' => 'shipped'])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['status']]]);
});

it('rejects a customer and an anonymous caller on the admin status endpoint', function () use ($paidOrder, $tokenFor) {
    $order = $paidOrder();
    $customer = User::factory()->create(['password' => 'correct-horse-battery']);

    withHeader('Authorization', 'Bearer '.$tokenFor($customer))
        ->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'shipped'])
        ->assertStatus(403);

    // withHeader() sets a *default* header that otherwise survives for the
    // rest of this test case — without flushing it here, this "anonymous"
    // call would silently still carry the customer's bearer token and
    // assert the wrong thing (403 again) instead of exercising the
    // no-token path at all.
    flushHeaders();

    patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'shipped'])
        ->assertStatus(401);
});

it('exposes isLegalNext as the single source of truth for valid edges', function () {
    $transitioner = app(OrderStatusTransitioner::class);

    expect($transitioner->isLegalNext(Order::STATUS_PENDING, Order::STATUS_PAID))->toBeTrue();
    expect($transitioner->isLegalNext(Order::STATUS_PENDING, Order::STATUS_SHIPPED))->toBeFalse();
    expect($transitioner->isLegalNext(Order::STATUS_DELIVERED, Order::STATUS_PENDING))->toBeFalse();
});
