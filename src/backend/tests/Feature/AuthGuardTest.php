<?php

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeader;

$login = fn (User $user): string => postJson('/api/v1/auth/login', [
    'email' => $user->email,
    'password' => 'correct-horse-battery',
])->json('token');

it('accepts a valid token', function () use ($login) {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);

    withHeader('Authorization', 'Bearer '.$login($user))
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.email', $user->email);
});

it('rejects a request with no token', function () {
    getJson('/api/v1/auth/me')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

it('rejects a malformed token', function () {
    withHeader('Authorization', 'Bearer not-a-jwt')
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

it('rejects an expired token', function () {
    $user = User::factory()->create();

    // Minted directly rather than through TokenService. The plan asked for
    // `new TokenService(config('jwt.secret'), -10, 'HS256')`, but Task 3's
    // service rejects a non-positive TTL in its constructor, so that line
    // throws InvalidArgumentException before it can issue anything and the
    // test never reaches the guard. Same key, same algorithm, same claim
    // shape as TokenService::issue(), with `exp` already two hours past.
    $issuedAt = time() - 7200;

    $expired = JWT::encode([
        'sub' => (string) $user->id,
        'role' => $user->role,
        'jti' => (string) Str::uuid(),
        'iat' => $issuedAt,
        'exp' => $issuedAt + 10,
    ], (string) config('jwt.secret'), (string) config('jwt.algo'));

    withHeader('Authorization', 'Bearer '.$expired)
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401);
});

it('rejects a token after logout, before its natural expiry', function () use ($login) {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);
    $token = $login($user);

    withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/auth/me')->assertOk();

    withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/auth/logout')->assertNoContent();

    // The same token, now refused — and its exp is still an hour away, so
    // this can only be the denylist doing the work.
    withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/auth/me')->assertStatus(401);
});

it('revokes only the token that logged out, not every token for that user', function () use ($login) {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);

    $phone = $login($user);
    $laptop = $login($user);

    withHeader('Authorization', 'Bearer '.$phone)
        ->postJson('/api/v1/auth/logout')->assertNoContent();

    withHeader('Authorization', 'Bearer '.$phone)->getJson('/api/v1/auth/me')->assertStatus(401);
    withHeader('Authorization', 'Bearer '.$laptop)->getJson('/api/v1/auth/me')->assertOk();
});

it('refuses to authenticate when the denylist cannot be read', function () use ($login) {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);
    $token = $login($user);

    // Both halves. While Redis answers, the token is accepted...
    withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/auth/me')->assertOk();

    // ...and when Redis is unreachable it is not. A revocation control that
    // answers "not revoked" during an outage silently re-validates every
    // logged-out token for the length of that outage, so the lookup has to be
    // allowed to throw. Nothing else in this suite can catch that regression:
    // wrapping isRevoked() in `try { ... } catch { return false; }` leaves the
    // other twelve green, because Redis is up for all of them.
    //
    // The real TokenDenylist and the real RedisManager are used — a stubbed
    // denylist that throws would only prove the guard propagates, and would
    // sail straight past a catch added inside the denylist itself.
    //
    // Three things memoise the live connection and all three have to go:
    // RedisManager snapshots config/database.php at first resolution, the
    // container holds it as a singleton, and AuthManager holds the guard
    // that was built around it.
    config([
        'database.redis.test.host' => '127.0.0.1',
        'database.redis.test.port' => 1,
    ]);
    app()->forgetInstance('redis');
    Redis::clearResolvedInstance('redis');
    Auth::forgetGuards();

    withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/auth/me')
        ->assertStatus(500)
        // Fails closed, and stays quiet doing it: no class name, no host,
        // no port, no trace, whatever APP_DEBUG says.
        ->assertExactJson(['error' => [
            'code' => 'INTERNAL_ERROR',
            'message' => 'An unexpected error occurred.',
        ]]);
});

it('returns an identical body for every kind of token failure', function () {
    $missing = getJson('/api/v1/auth/me')->json();
    $malformed = withHeader('Authorization', 'Bearer nonsense')->getJson('/api/v1/auth/me')->json();

    expect($missing)->toBe($malformed);
});
