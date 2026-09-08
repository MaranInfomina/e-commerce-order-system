<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\JwtGuard;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\TokenDenylist;
use App\Services\TokenService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;
use Throwable;

class AuthController extends Controller
{
    #[OA\Post(
        path: '/api/v1/auth/register',
        summary: 'Register a new customer account',
        requestBody: new OA\RequestBody(content: new OA\JsonContent(ref: '#/components/schemas/RegisterRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Account created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/User')])),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 429, description: 'Too many attempts', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ],
    )]
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            ...$request->validated(),
            'role' => User::ROLE_CUSTOMER,
        ]);

        return UserResource::make($user)->response()->setStatusCode(201);
    }

    /**
     * TokenService is injected per-method, not through the constructor.
     * Constructor injection resolves it for every action on this controller,
     * including register(), which never mints a token — and TokenService
     * refuses to construct without a signing key. That turned a missing
     * JWT_SECRET into a 500 on *registration* as well as login, which is
     * how a clean clone (empty `JWT_SECRET: ${JWT_SECRET:-}` in compose)
     * failed CR-3 before the entrypoint fallback existed. Only the action
     * that actually signs something depends on the key.
     */
    #[OA\Post(
        path: '/api/v1/auth/login',
        summary: 'Exchange credentials for a bearer token',
        requestBody: new OA\RequestBody(content: new OA\JsonContent(ref: '#/components/schemas/LoginRequest')),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Authenticated',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'token', type: 'string'),
                    new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
                    new OA\Property(property: 'expires_in', type: 'integer', description: 'Seconds until the token expires.'),
                ]),
            ),
            new OA\Response(response: 401, description: 'Invalid credentials', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 429, description: 'Too many attempts', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ],
    )]
    public function login(LoginRequest $request, TokenService $tokens): JsonResponse
    {
        $credentials = $request->validated();

        $user = User::where('email', $credentials['email'])->first();

        // One branch covers both "no such user" and "wrong password" so the
        // response cannot be used to enumerate accounts.
        //
        // A stored value that is absent OR empty is replaced by the
        // placeholder. `$user?->password ?? $placeholder` would not do this:
        // '' is not null, so an empty-password row would slip through to
        // Hash::check(), which returns false for '' *without comparing* —
        // a free, instantly-answered login attempt and a timing oracle.
        $stored = $user?->password;
        $hash = (is_string($stored) && $stored !== '')
            ? $stored
            : self::placeholderHash();

        // Run the comparison UNCONDITIONALLY, then branch on the result.
        // Writing `$user === null || ! Hash::check(...)` looks equivalent and
        // is not: `||` short-circuits, so Hash::check never executes for an
        // unknown email. That returns in ~1ms while a known email pays a full
        // bcrypt at cost 12 — a timing oracle that enumerates accounts just as
        // effectively as differing response bodies would. Assigning first is
        // what actually makes both paths cost the same.
        try {
            $passwordMatches = Hash::check($credentials['password'], $hash);
        } catch (Throwable $e) {
            // A stored digest the configured hasher cannot parse makes
            // check() throw, and an uncaught throw here is a 500 while every
            // other failed login is a 401 — distinguishable statuses are the
            // exact oracle the single branch above exists to suppress. Fail
            // closed instead, and log so a corrupted row is still diagnosable.
            // The hash and the submitted password are never logged.
            Log::warning('Password verification failed', [
                'user_id' => $user?->id,
                'reason' => $e->getMessage(),
            ]);

            $passwordMatches = false;
        }

        if ($user === null || ! $passwordMatches) {
            throw new AuthenticationException;
        }

        $issued = $tokens->issue($user);

        return response()->json([
            'token' => $issued['token'],
            'token_type' => 'Bearer',
            'expires_in' => $issued['expires_in'],
        ]);
    }

    /**
     * TokenDenylist is injected per-method for the same reason TokenService
     * is on login(): the plan asked for a constructor holding both, but this
     * controller's constructor was deliberately removed in Task 4 so that
     * register() cannot be brought down by a dependency it never uses.
     */
    #[OA\Post(
        path: '/api/v1/auth/logout',
        summary: "Revoke the caller's current bearer token",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 204, description: 'Token revoked'),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ],
    )]
    public function logout(TokenDenylist $denylist): JsonResponse
    {
        /** @var JwtGuard $guard */
        $guard = auth('api');
        $claims = $guard->claims();

        if (is_array($claims) && isset($claims['jti'], $claims['exp'])) {
            $denylist->revoke((string) $claims['jti'], (int) $claims['exp']);
        }

        return response()->json(null, 204);
    }

    #[OA\Get(
        path: '/api/v1/auth/me',
        summary: "Get the caller's own profile",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'The current user', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/User')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ],
    )]
    public function me(Request $request): JsonResponse
    {
        return UserResource::make($request->user())->response();
    }

    #[OA\Patch(
        path: '/api/v1/auth/me',
        summary: "Update the caller's own profile",
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(ref: '#/components/schemas/UpdateProfileRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Updated user', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/User')])),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ],
    )]
    public function updateMe(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update($request->validated());

        return UserResource::make($user->fresh())->response();
    }

    /**
     * A digest for the unknown-email path to compare against, so that branch
     * costs the same as the known-email one.
     *
     * Derived from the *configured* hasher rather than frozen into source. A
     * hardcoded bcrypt literal is only correct while bcrypt is the driver:
     * `HASH_DRIVER=argon2id` is an operator-settable environment variable, and
     * ArgonHasher::check() throws RuntimeException on a bcrypt digest, so a
     * literal would turn every unknown email into a 500 while known emails
     * stayed 401 — the enumeration oracle reintroduced by configuration alone.
     * The same applies, less loudly, to BCRYPT_ROUNDS: a literal pinned at
     * cost 12 against an application hashing at cost 10 is a measurable timing
     * difference between the two paths.
     *
     * Cached, because deriving it means running the hasher once — at cost 12
     * that is ~250ms, and paying it per request would make the unknown-email
     * path roughly twice as slow as the known-email one, recreating in the
     * cache-miss case the very asymmetry this exists to remove. The cache key
     * carries the driver and its options so changing either derives a fresh
     * one instead of serving a stale, now-unparseable digest.
     *
     * The hashed input is random and immediately discarded, so no password can
     * ever match the result and the stored value reveals nothing.
     */
    private static function placeholderHash(): string
    {
        $driver = (string) config('hashing.driver', 'bcrypt');
        $options = config('hashing.'.($driver === 'bcrypt' ? 'bcrypt' : 'argon'), []);

        $key = 'auth:placeholder-hash:'.$driver.':'.md5(serialize($options));

        return Cache::rememberForever($key, fn () => Hash::make(Str::random(64)));
    }
}
