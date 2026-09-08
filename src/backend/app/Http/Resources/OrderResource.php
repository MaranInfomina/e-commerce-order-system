<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Order',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'paid', 'payment_failed', 'shipped', 'delivered']),
        new OA\Property(property: 'shipping_address', type: 'string'),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'total_cents', type: 'integer'),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'product_id', type: 'integer'),
            new OA\Property(property: 'product_name', type: 'string'),
            new OA\Property(property: 'product_sku', type: 'string'),
            new OA\Property(property: 'image_url', type: 'string', nullable: true),
            new OA\Property(property: 'unit_price_cents', type: 'integer'),
            new OA\Property(property: 'quantity', type: 'integer'),
            new OA\Property(property: 'line_total_cents', type: 'integer'),
        ], type: 'object')),
        new OA\Property(property: 'status_history', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'status', type: 'string'),
            new OA\Property(property: 'caused_by', type: 'string'),
            new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        ], type: 'object')),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
)]
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'shipping_address' => $this->shipping_address,
            'notes' => $this->notes,
            'total_cents' => $this->total_cents,
            'items' => $this->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'product_sku' => $item->product_sku,
                'image_url' => $item->image_url,
                'unit_price_cents' => $item->unit_price_cents,
                'quantity' => $item->quantity,
                'line_total_cents' => $item->line_total_cents,
            ])->all(),
            'status_history' => $this->statusHistory->map(fn ($entry) => [
                'status' => $entry->status,
                'caused_by' => $entry->caused_by,
                'created_at' => $entry->created_at?->toIso8601String(),
            ])->all(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
