<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

class ProductStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Before validation, not after: without this a non-admin can read
        // per-field unique/exists results off a 422 and enumerate the
        // products table. Delegates to the policy rather than re-checking
        // isAdmin(), so there is one source of truth for who may write.
        return $this->user()?->can('create', Product::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:products,slug'],
            'sku' => ['required', 'string', 'max:64', 'unique:products,sku'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price_cents' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'stock_quantity' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'is_active' => ['boolean'],
        ];
    }
}
