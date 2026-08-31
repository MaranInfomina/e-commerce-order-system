<?php

namespace App\Http\Requests;

use App\Rules\FitsBcrypt;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
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
            // 12 characters minimum, 72 bytes maximum — the same ceiling
            // RegisterRequest enforces. `max:255` here would let a user
            // downgrade an account hardened at signup: bcrypt reads 72 bytes
            // and discards the rest, so any string sharing the first 72 bytes
            // would open the account.
            'password' => ['sometimes', 'string', 'min:12', new FitsBcrypt, 'confirmed'],
        ];
    }

    /**
     * Email is one identity, not one per capitalisation.
     *
     * RegisterRequest and LoginRequest both normalise here, and the users.email
     * unique index is an ordinary byte-exact one, so this is the invariant that
     * keeps it sufficient: every write and every lookup goes through a request
     * class that lowercases. Without it, PATCHing "Ada@Example.com" passes the
     * byte-exact unique check alongside an existing "ada@example.com" — two
     * accounts on one mailbox — and the owner can never log in again, because
     * LoginRequest lowercases before AuthController's byte-exact lookup.
     */
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => Str::lower($email)]);
        }
    }
}
