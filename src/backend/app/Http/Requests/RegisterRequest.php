<?php

namespace App\Http\Requests;

use App\Rules\FitsBcrypt;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'RegisterRequest',
    required: ['name', 'email', 'password', 'password_confirmation'],
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 255),
        new OA\Property(property: 'email', type: 'string', format: 'email', maxLength: 255),
        new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 12),
        new OA\Property(property: 'password_confirmation', type: 'string', format: 'password'),
    ],
)]
class RegisterRequest extends FormRequest
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
        // `role` is deliberately absent: validated() is what reaches
        // User::create(), so a submitted role is dropped rather than
        // rejected. Registration always produces a customer.
        return [
            'name' => ['required', 'string', 'max:255'],
            // The unique check runs against the lowercased address written by
            // prepareForValidation(), which is what makes "Ada@Example.com"
            // collide with an existing "ada@example.com" instead of creating a
            // second account on the same mailbox.
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            // 12 characters minimum, 72 bytes maximum. The ceiling is bcrypt's,
            // not a policy choice — see App\Rules\FitsBcrypt. The old `max:255`
            // let two different passwords that share their first 72 bytes open
            // the same account.
            'password' => ['required', 'string', 'min:12', new FitsBcrypt, 'confirmed'],
        ];
    }

    /**
     * Email is one identity, not one per capitalisation.
     *
     * Postgres string comparison is byte-exact and the users.email unique index
     * is an ordinary one, so without this "Ada@Example.com" registers cleanly
     * alongside "ada@example.com" — two accounts, one real mailbox, and two
     * different owners for everything Tasks 5-9 hang off user identity.
     * Normalising at the edge keeps the plain unique index sufficient: every
     * write and every lookup goes through a request class that does this.
     */
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => Str::lower($email)]);
        }
    }
}
