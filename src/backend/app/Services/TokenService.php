<?php

namespace App\Services;

use App\Exceptions\TokenInvalidException;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class TokenService
{
    /**
     * The shortest HS256 key firebase/php-jwt v7 will sign with.
     *
     * v7 exists because of CVE-2025-45769 ("weak encryption"), and the fix it
     * shipped is JWT::validateHmacKeyLength(), which throws DomainException
     * for any HMAC key under 32 bytes. v6 had no such check. Enforcing it here
     * rather than letting the library do it converts a confusing runtime
     * failure into a clear boot-time one: unguarded, a 31-byte secret
     * constructs fine, then throws an uncaught DomainException out of issue()
     * — a 500 at login — while inside parse() the same error is swallowed into
     * TokenInvalidException, so a misconfigured key presents as "every token
     * is invalid" with no diagnostic anywhere.
     */
    private const MIN_SECRET_BYTES = 32;

    /**
     * Claims the rest of the application indexes without checking.
     */
    private const REQUIRED_CLAIMS = ['sub', 'jti', 'exp'];

    public function __construct(
        private readonly string $secret,
        private readonly int $ttl,
        private readonly string $algo,
    ) {
        if ($this->secret === '') {
            // Failing at construction is deliberate: a default signing key
            // would let the application boot and issue forgeable tokens.
            throw new InvalidArgumentException('JWT_SECRET is not set.');
        }

        if (strlen($this->secret) < self::MIN_SECRET_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'JWT_SECRET must be at least %d bytes; got %d.',
                self::MIN_SECRET_BYTES,
                strlen($this->secret),
            ));
        }

        if ($this->ttl < 1) {
            // `(int) env('JWT_TTL')` turns any non-numeric value into 0, which
            // would issue tokens that expired at the moment they were signed —
            // surfacing, once again, as "every token is invalid".
            throw new InvalidArgumentException('JWT_TTL must be a positive number of seconds.');
        }
    }

    /**
     * @return array{token: string, expires_in: int}
     */
    public function issue(User $user): array
    {
        $issuedAt = time();

        $claims = [
            'sub' => (string) $user->id,
            // For the frontend's rendering decisions only. Server-side
            // authorization reads the database row, never this claim, so a
            // demotion takes effect immediately rather than at expiry.
            'role' => $user->role,
            'jti' => (string) Str::uuid(),
            'iat' => $issuedAt,
            'exp' => $issuedAt + $this->ttl,
        ];

        return [
            'token' => JWT::encode($claims, $this->secret, $this->algo),
            'expires_in' => $this->ttl,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function parse(string $token): array
    {
        try {
            // A single Key pinned to $this->algo. This is what closes
            // algorithm confusion: php-jwt compares the token's header `alg`
            // against the key's algorithm and refuses a mismatch, so neither
            // `alg: none` nor an HS/RS swap is accepted. Never derive the
            // algorithm from the token itself.
            $decoded = JWT::decode($token, new Key($this->secret, $this->algo));
        } catch (Throwable $e) {
            // The thrown exception stays detail-free: telling a caller that a
            // token is expired rather than forged tells an attacker which
            // tokens are live. But swallowing it silently makes an operator
            // error (short key, wrong algo, bad TTL) indistinguishable from an
            // attack in every observable, so the reason is logged server-side.
            // The token itself is never logged.
            Log::warning('JWT rejected', ['reason' => $e->getMessage()]);

            throw new TokenInvalidException;
        }

        $claims = (array) $decoded;

        // php-jwt enforces `exp` only when it is present, so a validly signed
        // token that simply omits it would otherwise be accepted forever. The
        // guard in Task 5 indexes these directly.
        foreach (self::REQUIRED_CLAIMS as $claim) {
            if (! array_key_exists($claim, $claims)) {
                Log::warning('JWT missing required claim', ['claim' => $claim]);

                throw new TokenInvalidException;
            }
        }

        return $claims;
    }
}
