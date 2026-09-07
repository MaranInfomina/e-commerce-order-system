<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use Illuminate\Support\Facades\DB;

/**
 * The one place any order status transition is written. Both the automatic
 * delayed jobs and the admin endpoint (a later task) call transition() —
 * never Order::update() directly — so a race between them is closed by
 * construction: whichever caller's guarded UPDATE affects zero rows treats
 * the transition as already applied and does nothing further, rather than
 * erroring or writing a duplicate history row.
 */
class OrderStatusTransitioner
{
    /**
     * @return bool  true when this call actually changed the row; false when
     *               the order was already at (or past) $to via another path —
     *               a no-op, not an error.
     */
    public function transition(Order $order, string $from, string $to, string $causedBy): bool
    {
        $affected = DB::table('orders')
            ->where('id', $order->id)
            ->where('status', $from)
            ->update(['status' => $to, 'updated_at' => now()]);

        if ($affected === 0) {
            return false;
        }

        OrderStatusHistory::create([
            'order_id' => $order->id,
            'status' => $to,
            'caused_by' => $causedBy,
        ]);

        $order->status = $to;

        return true;
    }
}
