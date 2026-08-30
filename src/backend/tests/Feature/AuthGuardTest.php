<?php

use App\Models\User;
use Firebase\JWT\JWT;
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

it('returns an identical body for every kind of token failure', function () {
    $missing = getJson('/api/v1/auth/me')->json();
    $malformed = withHeader('Authorization', 'Bearer nonsense')->getJson('/api/v1/auth/me')->json();

    expect($missing)->toBe($malformed);
});
