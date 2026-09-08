<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalesReportRequest;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class ReportController extends Controller
{
    #[OA\Get(
        path: '/api/v1/reports/sales',
        summary: 'Sales report grouped by day (admin only)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', description: 'Defaults to 29 days before `to`.', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', description: 'Defaults to today.', schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Aggregated sales, counting only paid/shipped/delivered orders',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'total_orders', type: 'integer'),
                        new OA\Property(property: 'total_revenue_cents', type: 'integer'),
                        new OA\Property(property: 'orders_per_day', type: 'array', items: new OA\Items(properties: [
                            new OA\Property(property: 'date', type: 'string', format: 'date'),
                            new OA\Property(property: 'orders', type: 'integer'),
                            new OA\Property(property: 'revenue_cents', type: 'integer'),
                        ], type: 'object')),
                    ], type: 'object'),
                ]),
            ),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 403, description: 'Not an admin', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ],
    )]
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
