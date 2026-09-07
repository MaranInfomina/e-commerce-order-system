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

        // A later task adds AdvanceOrderToDelivered and dispatches it here.
    }
}
