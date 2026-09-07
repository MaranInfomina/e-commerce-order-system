<?php

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderStatusUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Before validation, not after — a non-admin must never learn
        // anything about what a valid body would have looked like.
        return $this->user()?->can('updateStatus', $this->route('order')) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        // Only the two states an admin may set directly — payment outcomes
        // are never admin-settable, only the automatic job chain or the
        // FAIL_PAYMENT marker produce them.
        return [
            'status' => ['required', 'string', Rule::in([Order::STATUS_SHIPPED, Order::STATUS_DELIVERED])],
        ];
    }
}
