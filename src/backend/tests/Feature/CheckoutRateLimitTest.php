<?php

use App\Models\Product;
use App\Models\User;
use App\Repositories\CartRepository;

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

$checkoutOnce = function (User $user, string $token) {
    $product = Product::factory()->create(['stock_quantity' => 1000]);
    app(CartRepository::class)->setQuantity($user->id, $product->id, 1);

    return withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/orders', ['shipping_address' => '221B Baker Street']);
};

it('throttles a burst of checkouts from one user past the configured limit', function () use ($tokenFor, $checkoutOnce) {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);
    $token = $tokenFor($user);

    $limit = 20;
    $statuses = [];

    for ($i = 0; $i < $limit + 5; $i++) {
        $statuses[] = $checkoutOnce($user, $token)->getStatusCode();
    }

    expect($statuses)->toContain(429);
    $firstThrottledIndex = array_search(429, $statuses, true);
    expect(array_slice($statuses, $firstThrottledIndex))->each->toBe(429);
});

it('does not throttle a second user in the same window', function () use ($tokenFor, $checkoutOnce) {
    $alice = User::factory()->create(['password' => 'correct-horse-battery']);
    $bob = User::factory()->create(['password' => 'correct-horse-battery']);
    $aliceToken = $tokenFor($alice);
    $bobToken = $tokenFor($bob);

    for ($i = 0; $i < 25; $i++) {
        $checkoutOnce($alice, $aliceToken);
    }

    expect($checkoutOnce($bob, $bobToken)->getStatusCode())->toBe(201);
});
