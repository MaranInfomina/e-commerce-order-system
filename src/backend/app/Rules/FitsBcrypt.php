<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects a password longer than bcrypt can actually hash.
 *
 * bcrypt reads at most 72 bytes of its input and silently discards the rest,
 * so "hunter2<65 bytes of padding>A" and "hunter2<the same padding>B" produce
 * the same digest and both authenticate the same account. Capping the input
 * is the only way to stop a password's tail from being decorative.
 *
 * Bytes, not characters. Laravel's `max` rule measures with Str::length()
 * (mb_strlen), so `max:72` still admits a 72-character password that is 216
 * bytes of UTF-8 — exactly the case the cap exists to exclude.
 */
class FitsBcrypt implements ValidationRule
{
    /**
     * The last byte offset bcrypt reads. Not configurable: it is a property of
     * the algorithm, not of this application's policy.
     */
    public const MAX_BYTES = 72;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Non-strings are the `string` rule's problem; reporting them here too
        // would put two messages on one field for a single mistake.
        if (! is_string($value)) {
            return;
        }

        if (strlen($value) > self::MAX_BYTES) {
            $fail('The :attribute must not be longer than '.self::MAX_BYTES.' bytes.');
        }
    }
}
