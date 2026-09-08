<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CartItemRequest',
    description: 'product_id is required when adding a new line (POST /cart/items); the update route (PATCH /cart/items/{product}) takes the product from the URL and only reads quantity.',
    required: ['quantity'],
    properties: [
        new OA\Property(property: 'product_id', type: 'integer', description: 'Required on POST /cart/items, ignored on PATCH /cart/items/{product}.'),
        new OA\Property(property: 'quantity', type: 'integer', minimum: 1, maximum: CartItemRequest::MAX_QUANTITY),
    ],
)]
class CartItemRequest extends FormRequest
{
    /** Ceiling on the stored quantity of a single cart line. */
    public const MAX_QUANTITY = 1000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // product_id is only required when adding; the update route takes
        // the product from the URL.
        $rules = [
            // Bounds the DELTA, not the stored quantity — POST increments, so
            // two requests of 1000 leave the line at 2000. CartController
            // clamps the post-increment value; this rule only keeps an
            // oversized integer from reaching Redis in one hop.
            'quantity' => ['required', 'integer', 'min:1', 'max:'.self::MAX_QUANTITY],
        ];

        if ($this->routeIs('*cart.items.store')) {
            $rules['product_id'] = [
                'required',
                'integer',
                // whereNull('deleted_at'): Product uses SoftDeletes and a bare
                // `exists` matches deleted rows. Without it, adding a
                // soft-deleted product validates, gets HINCRBY'd in, and is
                // swept straight back out by resolve() in the same request —
                // a 201 whose body is an empty cart. A nonexistent id already
                // 422s; a deleted one should answer the same way.
                Rule::exists('products', 'id')->whereNull('deleted_at'),
            ];
        }

        return $rules;
    }
}
