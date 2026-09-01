<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CartItemRequest extends FormRequest
{
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
            // Bounded above for the same reason price_cents is: an oversized
            // integer would otherwise reach Redis and come back as a string
            // that breaks the line-total arithmetic.
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
        ];

        if ($this->routeIs('*cart.items.store')) {
            $rules['product_id'] = ['required', 'integer', 'exists:products,id'];
        }

        return $rules;
    }
}
