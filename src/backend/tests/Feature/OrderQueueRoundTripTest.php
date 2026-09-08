<?php

use App\Models\Product;
use App\Models\User;
use App\Repositories\CartRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeader;

// This is the ONE test in the suite that exercises the real broker AND real
// mail catcher end to end. Every other payment test above runs under the
// sync connection phpunit.xml forces.
it('processes a real order through rabbitmq and delivers a real email via mailpit', function () {
    try {
        app('queue')->connection('rabbitmq')->size('orders');
    } catch (Throwable $e) {
        test()->markTestSkipped('RabbitMQ is not reachable: '.$e->getMessage());
    }

    $mailpitUp = rescue(fn () => Http::timeout(2)->get('http://mailpit:8025/api/v1/info'), null, false);

    if ($mailpitUp === null || ! $mailpitUp->successful()) {
        test()->markTestSkipped('Mailpit is not reachable.');
    }

    config(['queue.default' => 'rabbitmq', 'mail.default' => 'smtp']);

    $user = User::factory()->create(['password' => 'correct-horse-battery']);
    $token = postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct-horse-battery'])->json('token');
    $product = Product::factory()->create(['stock_quantity' => 5]);
    app(CartRepository::class)->setQuantity($user->id, $product->id, 1);

    $subjectMarker = 'coe-roundtrip-'.uniqid();

    withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/orders', ['shipping_address' => $subjectMarker])
        ->assertCreated();

    // Drain the two chained jobs from the real queue, in this process.
    Artisan::call('queue:work', [
        'connection' => 'rabbitmq', '--queue' => 'orders', '--once' => true, '--stop-when-empty' => true,
    ]);
    Artisan::call('queue:work', [
        'connection' => 'rabbitmq', '--queue' => 'orders', '--once' => true, '--stop-when-empty' => true,
    ]);

    $search = Http::get('http://mailpit:8025/api/v1/search', ['query' => "to:{$user->email}"]);

    expect($search->successful())->toBeTrue();
    expect($search->json('total'))->toBeGreaterThanOrEqual(1);
});
