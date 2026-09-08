<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * Wraps an already-resolved array shaped
 * ['items' => [['product' => Product, 'quantity' => int], ...]].
 */
#[OA\Schema(
    schema: 'Cart',
    properties: [
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'product', ref: '#/components/schemas/Product'),
            new OA\Property(property: 'quantity', type: 'integer'),
            new OA\Property(property: 'line_total_cents', type: 'integer'),
        ], type: 'object')),
        new OA\Property(property: 'total_cents', type: 'integer'),
        new OA\Property(property: 'item_count', type: 'integer'),
    ],
)]
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
