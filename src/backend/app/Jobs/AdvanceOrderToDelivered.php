<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\OrderStatusTransitioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AdvanceOrderToDelivered implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $orderId) {}

    public function handle(OrderStatusTransitioner $transitioner): void
    {
        $order = Order::findOrFail($this->orderId);

        $transitioner->transition($order, Order::STATUS_SHIPPED, Order::STATUS_DELIVERED, 'system');
    }
}
