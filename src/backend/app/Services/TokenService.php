<?php

namespace App\Services;

use App\Exceptions\TokenInvalidException;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class TokenService
{
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
            $decoded = JWT::decode($token, new Key($this->secret, $this->algo));
        } catch (Throwable) {
            // Every failure mode collapses to one exception with no detail.
            throw new TokenInvalidException;
        }

        return (array) $decoded;
    }
}
