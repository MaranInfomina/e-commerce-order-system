<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'OrderStoreRequest',
    required: ['shipping_address'],
    properties: [
        new OA\Property(property: 'shipping_address', type: 'string', maxLength: 1000),
        new OA\Property(property: 'notes', type: 'string', maxLength: 2000, nullable: true),
    ],
)]
class OrderStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'shipping_address' => ['required', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
