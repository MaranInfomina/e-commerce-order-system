<?php

namespace App\Http\Requests;

use App\Queries\ProductListQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductIndexRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'page' => ['integer', 'min:1'],
            'per_page' => ['integer', 'min:1', 'max:100'],
            'search' => ['string', 'max:255'],
            'category' => ['string', 'exists:categories,slug'],
            'min_price' => ['integer', 'min:0'],
            // gte:min_price only applies when min_price is present — Laravel's
            // gte rule fails (rather than passing vacuously) when the
            // comparison field is absent, which would wrongly reject a
            // request that supplies only max_price.
            'max_price' => ['integer', 'min:0', Rule::when($this->filled('min_price'), ['gte:min_price'])],
            'is_active' => ['boolean'],
            'sort' => ['string', Rule::in(array_keys(ProductListQuery::SORTS))],
        ];
    }
}
