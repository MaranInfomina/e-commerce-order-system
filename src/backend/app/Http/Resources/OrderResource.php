<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
