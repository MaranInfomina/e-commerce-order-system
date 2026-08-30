<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AuthController extends Controller
{
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
