<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalesReportRequest;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function sales(SalesReportRequest $request): JsonResponse
    {
        $to = $request->filled('to')
            ? Carbon::parse($request->validated('to'))->endOfDay()
            : now()->endOfDay();

        $from = $request->filled('from')
            ? Carbon::parse($request->validated('from'))->startOfDay()
            : $to->copy()->subDays(29)->startOfDay();

        // Exactly one query regardless of the range's length — the
        // aggregation happens in Postgres via GROUP BY, not in a per-day loop.
        $rows = DB::table('orders')
            ->selectRaw("date_trunc('day', created_at) as day, COUNT(*) as orders_count, SUM(total_cents) as revenue_cents")
            ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_DELIVERED])
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $ordersPerDay = $rows->map(fn ($row) => [
            'date' => Carbon::parse($row->day)->toDateString(),
            'orders' => (int) $row->orders_count,
            'revenue_cents' => (int) $row->revenue_cents,
        ])->values()->all();

        return response()->json(['data' => [
            'total_orders' => array_sum(array_column($ordersPerDay, 'orders')),
            'total_revenue_cents' => array_sum(array_column($ordersPerDay, 'revenue_cents')),
            'orders_per_day' => $ordersPerDay,
        ]]);
    }
}
