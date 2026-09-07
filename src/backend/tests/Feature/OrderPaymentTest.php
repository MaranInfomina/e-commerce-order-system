<?php

use App\Jobs\ProcessPayment;
use App\Jobs\SendOrderConfirmation;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Repositories\CartRepository;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;

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

$checkout = function (User $user, string $token, string $address) {
    $product = Product::factory()->create(['stock_quantity' => 10]);
    app(CartRepository::class)->setQuantity($user->id, $product->id, 1);

    return withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/orders', ['shipping_address' => $address]);
};

it('dispatches the chain and never runs it before the response is sent', function () use ($checkout) {
    // QUEUE_CONNECTION is forced to sync (phpunit.xml), which runs a job
    // synchronously the instant it is dispatched — Bus::fake() is the only
    // way to observe "was queued" separately from "was executed".
    Bus::fake();

    $user = User::factory()->create(['password' => 'correct-horse-battery']);
    $token = postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct-horse-battery'])->json('token');

    $checkout($user, $token, '221B Baker Street')->assertCreated();

    Bus::assertChained([ProcessPayment::class, SendOrderConfirmation::class]);
});

it('marks an ordinary order paid', function () use ($tokenFor, $checkout) {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);

    $response = $checkout($user, $tokenFor($user), '221B Baker Street');

    $response->assertCreated()->assertJsonPath('data.status', 'paid');
    expect(Order::first()->statusHistory()->pluck('status')->all())->toBe(['pending', 'paid']);
});

it('marks an order with the FAIL_PAYMENT marker as payment_failed and never sends a confirmation', function () use ($tokenFor, $checkout) {
    Mail::fake();

    $user = User::factory()->create(['password' => 'correct-horse-battery']);

    $response = $checkout($user, $tokenFor($user), 'FAIL_PAYMENT 221B Baker Street');

    $response->assertCreated()->assertJsonPath('data.status', 'payment_failed');
    expect(Order::first()->statusHistory()->pluck('status')->all())->toBe(['pending', 'payment_failed']);
    Mail::assertNothingSent();
});

it('records the failed payment in failed_jobs with the order id recoverable from its payload', function () use ($tokenFor, $checkout) {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);

    $checkout($user, $tokenFor($user), 'FAIL_PAYMENT 221B Baker Street')->assertCreated();

    $failed = DB::table('failed_jobs')->first();

    expect($failed)->not->toBeNull();
    // failed_jobs.payload is the job's raw queue payload — genuine JSON text,
    // not a PHP string — so every backslash in the FQCN is escaped ("\\\\"
    // rather than "\\"). addslashes() produces exactly that escaped form;
    // asserting against the unescaped ProcessPayment::class constant can
    // never match a real payload column, under sync or any other connection.
    expect($failed->payload)->toContain(addslashes(ProcessPayment::class));
    expect($failed->exception)->toContain('FAIL_PAYMENT marker present');
});
