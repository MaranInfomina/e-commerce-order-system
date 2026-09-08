<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ProductUpdateRequest',
    description: 'All fields are optional (sometimes-validated); only the fields present are updated.',
    properties: [
        new OA\Property(property: 'category_id', type: 'integer'),
        new OA\Property(property: 'name', type: 'string', maxLength: 255),
        new OA\Property(property: 'slug', type: 'string', maxLength: 255),
        new OA\Property(property: 'sku', type: 'string', maxLength: 64),
        new OA\Property(property: 'description', type: 'string', maxLength: 5000, nullable: true),
        new OA\Property(property: 'price_cents', type: 'integer', minimum: 0),
        new OA\Property(property: 'stock_quantity', type: 'integer', minimum: 0),
        new OA\Property(property: 'is_active', type: 'boolean'),
    ],
)]
class ProductUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('product')) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $productId = $this->route('product')->id;

        return [
            'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => [
                'sometimes', 'string', 'max:255', 'alpha_dash',
                Rule::unique('products', 'slug')->ignore($productId),
            ],
            'sku' => [
                'sometimes', 'string', 'max:64',
                Rule::unique('products', 'sku')->ignore($productId),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'price_cents' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            'stock_quantity' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
