<?php

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\flushHeaders;
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

it('rejects a customer and an anonymous caller', function () use ($tokenFor) {
    $customer = User::factory()->create(['password' => 'correct-horse-battery']);

    withHeader('Authorization', 'Bearer '.$tokenFor($customer))
        ->getJson('/api/v1/reports/sales')
        ->assertStatus(403);

    // withHeader() sets a *default* header that otherwise survives for the
    // rest of this test case (see the identical note in OrderStatusTest) —
    // without flushing it here, this "anonymous" call would silently still
    // carry the customer's bearer token and assert 403 again instead of
    // actually exercising the no-token path.
    flushHeaders();

    getJson('/api/v1/reports/sales')->assertStatus(401);
});

it('sums totals across several days and users, counting only paid-or-later orders', function () use ($tokenFor) {
    $admin = User::factory()->admin()->create(['password' => 'correct-horse-battery']);
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    Order::factory()->for($alice)->status(Order::STATUS_PAID)->create([
        'total_cents' => 1000, 'created_at' => Carbon::parse('2026-01-05 10:00:00'),
    ]);
    Order::factory()->for($bob)->status(Order::STATUS_DELIVERED)->create([
        'total_cents' => 2500, 'created_at' => Carbon::parse('2026-01-05 14:00:00'),
    ]);
    Order::factory()->for($alice)->status(Order::STATUS_SHIPPED)->create([
        'total_cents' => 500, 'created_at' => Carbon::parse('2026-01-06 09:00:00'),
    ]);
    // Excluded: never paid.
    Order::factory()->for($bob)->status(Order::STATUS_PAYMENT_FAILED)->create([
        'total_cents' => 9999, 'created_at' => Carbon::parse('2026-01-05 12:00:00'),
    ]);
    Order::factory()->for($alice)->status(Order::STATUS_PENDING)->create([
        'total_cents' => 9999, 'created_at' => Carbon::parse('2026-01-05 12:00:00'),
    ]);

    $response = withHeader('Authorization', 'Bearer '.$tokenFor($admin))
        ->getJson('/api/v1/reports/sales?from=2026-01-01&to=2026-01-31');

    $response->assertOk()
        ->assertJsonPath('data.total_orders', 3)
        ->assertJsonPath('data.total_revenue_cents', 4000)
        ->assertJsonCount(2, 'data.orders_per_day');

    $days = collect($response->json('data.orders_per_day'))->keyBy('date');
    expect($days['2026-01-05']['orders'])->toBe(2);
    expect($days['2026-01-05']['revenue_cents'])->toBe(3500);
    expect($days['2026-01-06']['orders'])->toBe(1);
    expect($days['2026-01-06']['revenue_cents'])->toBe(500);
});

it('answers with a constant query count regardless of the date range size', function () use ($tokenFor) {
    $admin = User::factory()->admin()->create(['password' => 'correct-horse-battery']);
    $token = $tokenFor($admin);

    Order::factory()->status(Order::STATUS_PAID)->create(['created_at' => Carbon::parse('2026-01-15')]);

    DB::enableQueryLog();
    withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/reports/sales?from=2026-01-01&to=2026-01-02')
        ->assertOk();
    $shortRangeCount = count(DB::getQueryLog());
    DB::flushQueryLog();

    withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/reports/sales?from=2020-01-01&to=2026-12-31')
        ->assertOk();
    $longRangeCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($longRangeCount)->toBe($shortRangeCount);
});

it('defaults to the last 30 days when no range is given', function () use ($tokenFor) {
    $admin = User::factory()->admin()->create(['password' => 'correct-horse-battery']);

    Order::factory()->status(Order::STATUS_PAID)->create(['created_at' => now()->subDays(5)]);
    Order::factory()->status(Order::STATUS_PAID)->create(['created_at' => now()->subDays(60)]);

    withHeader('Authorization', 'Bearer '.$tokenFor($admin))
        ->getJson('/api/v1/reports/sales')
        ->assertOk()
        ->assertJsonPath('data.total_orders', 1);
});
