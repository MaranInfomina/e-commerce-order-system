<?php

namespace App\Http\Requests;

use App\Rules\FitsBcrypt;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class LoginRequest extends FormRequest
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
        return [
            'email' => ['required', 'string', 'email'],
            // No `min` — a length floor on login is a policy statement about
            // stored passwords, and enforcing it here only turns a wrong
            // password into a 422 instead of the uniform 401. The byte ceiling
            // is different: it is the same cap registration applies, so a
            // password this endpoint would accept is one bcrypt can actually
            // verify, and an unbounded field is a free way to make the server
            // hash megabytes.
            'password' => ['required', 'string', new FitsBcrypt],
        ];
    }

    /**
     * Lowercased for the same reason RegisterRequest lowercases: the lookup in
     * AuthController is a byte-exact `where('email', ...)`, so without this the
     * account created as "ada@example.com" cannot be logged into as
     * "Ada@Example.com" — and, worse, the two would be separate accounts.
     */
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => Str::lower($email)]);
        }
    }
}
