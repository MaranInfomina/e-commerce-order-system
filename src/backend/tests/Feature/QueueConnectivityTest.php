<?php

use Illuminate\Support\Facades\Artisan;
use Tests\Support\QueueConnectivityProbeJob;

// Mirrors tests/Feature/StorageConnectivityTest.php's shape: skip when the
// real dependency is not reachable (so a clean-clone `docker compose up`
// without rabbitmq started yet does not fail this test), never silently pass
// when it IS reachable but broken.
$requiresRabbitMq = function (): void {
    try {
        app('queue')->connection('rabbitmq')->size('orders');
    } catch (\Throwable $e) {
        test()->markTestSkipped('RabbitMQ is not reachable: '.$e->getMessage());
    }
};

it('round-trips a job through real rabbitmq', function () use ($requiresRabbitMq) {
    $requiresRabbitMq();

    QueueConnectivityProbeJob::$handled = false;

    QueueConnectivityProbeJob::dispatch()->onConnection('rabbitmq')->onQueue('orders');

    // --once + --stop-when-empty: process exactly the one message just
    // published, then return control to the test instead of blocking forever
    // the way a long-running worker would.
    Artisan::call('queue:work', [
        'connection' => 'rabbitmq',
        '--queue' => 'orders',
        '--once' => true,
        '--stop-when-empty' => true,
    ]);

    expect(QueueConnectivityProbeJob::$handled)->toBeTrue();
});

it('declares the rabbitmq connection with the orders queue as its default', function () {
    expect(config('queue.connections.rabbitmq.driver'))->toBe('rabbitmq');
    expect(config('queue.connections.rabbitmq.queue'))->toBe('orders');
});
