<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps an already-resolved array shaped
 * ['items' => [['product' => Product, 'quantity' => int], ...]].
 */
class CartResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $items = [];
        $totalCents = 0;
        $itemCount = 0;

        foreach ($this->resource['items'] as $line) {
            // Integer arithmetic throughout — DEC-8. No float ever touches
            // a price, including a subtotal.
            $lineTotal = $line['product']->price_cents * $line['quantity'];

            $items[] = [
                'product' => ProductResource::make($line['product']),
                'quantity' => $line['quantity'],
                'line_total_cents' => $lineTotal,
            ];

            $totalCents += $lineTotal;
            $itemCount += $line['quantity'];
        }

        return [
            'items' => $items,
            'total_cents' => $totalCents,
            'item_count' => $itemCount,
        ];
    }
}
