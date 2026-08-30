<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
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
        // `role` is absent by design: validated() is what reaches update(),
        // so a submitted role is silently dropped and a user cannot promote
        // themselves through this endpoint.
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->user()->id),
            ],
            'password' => ['sometimes', 'string', 'min:12', 'max:255', 'confirmed'],
        ];
    }
}
