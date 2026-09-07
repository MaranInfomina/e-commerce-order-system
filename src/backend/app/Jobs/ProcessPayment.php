<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\OrderStatusTransitioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class ProcessPayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    // The order id, not the Order model: keeps the queued payload tiny and
    // makes every attempt re-read current data rather than a stale snapshot
    // serialized at dispatch time.
    public function __construct(public readonly int $orderId) {}

    public function handle(OrderStatusTransitioner $transitioner): void
    {
        $order = Order::findOrFail($this->orderId);

        // A literal marker in the address a reviewer can trigger on demand,
        // chosen over a random or numeric trigger specifically because it
        // cannot be hit by accident in ordinary fixture data.
        if (str_contains($order->shipping_address, 'FAIL_PAYMENT')) {
            throw new RuntimeException("Mock payment failed for order {$order->id} (FAIL_PAYMENT marker present).");
        }

        $transitioner->transition($order, Order::STATUS_PENDING, Order::STATUS_PAID, 'system');
    }

    /**
     * Called once, after every retry is exhausted — not on each individual
     * attempt, so an order does not flash to payment_failed while still
     * mid-retry. Bus::chain stops here: SendOrderConfirmation, chained after
     * this job, is never dispatched when this method runs.
     */
    public function failed(?Throwable $exception): void
    {
        $order = Order::find($this->orderId);

        if ($order !== null) {
            app(OrderStatusTransitioner::class)
                ->transition($order, Order::STATUS_PENDING, Order::STATUS_PAYMENT_FAILED, 'system');
        }
    }
}
