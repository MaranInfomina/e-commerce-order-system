<?php

namespace App\Auth;

use App\Exceptions\TokenInvalidException;
use App\Models\User;
use App\Services\TokenDenylist;
use App\Services\TokenService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;

class JwtGuard implements Guard
{
    private ?User $user = null;

    private bool $resolved = false;

    public function __construct(
        private readonly TokenService $tokens,
        private readonly TokenDenylist $denylist,
        private Request $request,
    ) {}

    /**
     * Point the guard at a new request and forget everything it resolved
     * from the previous one.
     *
     * AuthManager memoises the guard for the container's lifetime, so a
     * single container serving several requests — the test suite, Octane —
     * would otherwise keep answering from the first request's token and its
     * resolved user. That turns revocation into a no-op after the first
     * authenticated call of a test. AuthManager wires stock guards up the
     * same way (`$this->app->refresh('request', $guard, 'setRequest')`);
     * a custom driver has to do it in its own factory, which
     * AppServiceProvider::boot() does.
     */
    public function setRequest(Request $request): void
    {
        $this->request = $request;
        $this->user = null;
        $this->resolved = false;
    }

    public function user(): ?Authenticatable
    {
        if ($this->resolved) {
            return $this->user;
        }

        $this->resolved = true;
        $this->user = $this->resolveFromRequest();

        return $this->user;
    }

    private function resolveFromRequest(): ?User
    {
        $claims = $this->claims();

        if ($claims === null) {
            return null;
        }

        $jti = $claims['jti'] ?? null;

        if (! is_string($jti) || $jti === '') {
            return null;
        }

        // Fails CLOSED: if Redis is unreachable this throws rather than
        // returning false, so an outage cannot silently re-validate every
        // logged-out token. Refusing to serve is the safe direction for a
        // revocation control (DEC-17).
        if ($this->denylist->isRevoked($jti)) {
            return null;
        }

        // The role is read from the database row, never from the token's
        // `role` claim, so a demotion takes effect immediately rather than
        // at token expiry.
        return User::find($claims['sub'] ?? null);
    }

    /**
     * The decoded claims for the current request, or null when there is no
     * token or it does not verify.
     *
     * @return array<string, mixed>|null
     */
    public function claims(): ?array
    {
        $token = $this->request->bearerToken();

        if ($token === null || $token === '') {
            return null;
        }

        try {
            return $this->tokens->parse($token);
        } catch (TokenInvalidException) {
            return null;
        }
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function id(): int|string|null
    {
        return $this->user()?->getAuthIdentifier();
    }

    /** @param array<string, mixed> $credentials */
    public function validate(array $credentials = []): bool
    {
        // Credential validation lives in AuthController::login; this guard
        // only resolves tokens.
        return false;
    }

    public function hasUser(): bool
    {
        return $this->user !== null;
    }

    public function setUser(Authenticatable $user): void
    {
        $this->user = $user;
        $this->resolved = true;
    }
}
