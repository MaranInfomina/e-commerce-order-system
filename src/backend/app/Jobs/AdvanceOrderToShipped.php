<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\OrderStatusTransitioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AdvanceOrderToShipped implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $orderId) {}

    public function handle(OrderStatusTransitioner $transitioner): void
    {
        $order = Order::findOrFail($this->orderId);

        // A no-op if the admin endpoint already moved this order past `paid`
        // — transition() decides that; this job never pre-checks.
        $transitioner->transition($order, Order::STATUS_PAID, Order::STATUS_SHIPPED, 'system');

        // Same reasoning as SendOrderConfirmation's guard around its own
        // delayed dispatch: under the sync connection (phpunit.xml forces
        // this for the whole suite) ->delay() is a no-op and the job would
        // run inline, immediately advancing a just-shipped order straight to
        // delivered within this same call. Skipping the dispatch when the
        // default connection is sync keeps delayed scheduling semantics
        // identical between test and production instead of silently
        // collapsing to zero delay whenever sync is in effect.
        if (config('queue.default') !== 'sync') {
            AdvanceOrderToDelivered::dispatch($order->id)
                ->onQueue('orders')
                ->delay(now()->addSeconds(30));
        }
    }
}
