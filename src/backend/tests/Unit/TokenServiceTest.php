<?php

use App\Exceptions\TokenInvalidException;
use App\Models\User;
use App\Services\TokenService;
use Firebase\JWT\JWT;

// 32 bytes is the shortest key firebase/php-jwt v7 will sign HS256 with.
const TEST_SECRET = 'test-secret-at-least-32-characters-long';

$service = fn (): TokenService => new TokenService(
    secret: TEST_SECRET,
    ttl: 3600,
    algo: 'HS256',
);

$user = fn (): User => tap(new User, function (User $u) {
    $u->id = 42;
    $u->role = 'admin';
});

it('issues a token carrying the claims the guard needs', function () use ($service, $user) {
    $issued = $service()->issue($user());

    expect($issued)->toHaveKeys(['token', 'expires_in']);
    expect($issued['expires_in'])->toBe(3600);

    $claims = $service()->parse($issued['token']);

    expect($claims['sub'])->toBe('42');
    expect($claims['role'])->toBe('admin');
    expect($claims['jti'])->toBeString()->not->toBeEmpty();
    expect($claims['exp'])->toBeGreaterThan($claims['iat']);
});

it('gives every token a distinct jti so revocation targets one token', function () use ($service, $user) {
    $first = $service()->parse($service()->issue($user())['token']);
    $second = $service()->parse($service()->issue($user())['token']);

    expect($first['jti'])->not->toBe($second['jti']);
});

it('rejects a token signed with a different secret', function () use ($service, $user) {
    $issuedElsewhere = (new TokenService('a-completely-different-secret-value-32', 3600, 'HS256'))
        ->issue($user())['token'];

    expect(fn () => $service()->parse($issuedElsewhere))->toThrow(TokenInvalidException::class);
});

it('rejects an expired token', function () use ($service) {
    // Encoded directly rather than by issuing with a negative TTL: the
    // constructor now rejects a non-positive TTL, and this is the more honest
    // test anyway — it exercises parse() rejecting an expired token, not
    // issue()'s willingness to mint one.
    $expired = JWT::encode([
        'sub' => '42',
        'role' => 'admin',
        'jti' => 'expired-token',
        'iat' => time() - 100,
        'exp' => time() - 10,
    ], TEST_SECRET, 'HS256');

    expect(fn () => $service()->parse($expired))->toThrow(TokenInvalidException::class);
});

it('rejects a malformed token', function () use ($service) {
    expect(fn () => $service()->parse('not-a-jwt'))->toThrow(TokenInvalidException::class);
});

it('rejects an unsigned token claiming alg none', function () use ($service) {
    // Algorithm confusion, the classic JWT break. parse() pins the algorithm
    // by passing a single Key, so php-jwt refuses a header that disagrees.
    // Without a test here, an implementation that read `alg` from the token
    // itself would pass every other case in this file.
    $header = rtrim(strtr(base64_encode('{"typ":"JWT","alg":"none"}'), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode(json_encode([
        'sub' => '42', 'role' => 'admin', 'jti' => 'forged', 'iat' => time(), 'exp' => time() + 3600,
    ])), '+/', '-_'), '=');

    expect(fn () => $service()->parse($header.'.'.$payload.'.'))
        ->toThrow(TokenInvalidException::class);
});

it('rejects a token signed with a different algorithm', function () {
    // Same secret, different algorithm. Accepting this would let an attacker
    // choose the algorithm, which is the other half of algorithm confusion.
    //
    // A 64-byte secret because v7's minimum key length scales with the hash:
    // HS256 needs 32 bytes, HS512 needs 64. Signing HS512 with a 32-byte key
    // throws inside the test helper before parse() is ever reached, which
    // would make this test pass for entirely the wrong reason.
    $secret = str_repeat('k', 64);
    $service = new TokenService($secret, 3600, 'HS256');

    $hs512 = JWT::encode([
        'sub' => '42', 'role' => 'admin', 'jti' => 'wrong-algo', 'iat' => time(), 'exp' => time() + 3600,
    ], $secret, 'HS512');

    expect(fn () => $service->parse($hs512))->toThrow(TokenInvalidException::class);
});

it('rejects a validly signed token that omits a required claim', function () use ($service) {
    // php-jwt enforces exp only when present, so without this check a signed
    // token with no exp would be accepted forever. Task 5's guard indexes
    // sub and jti directly.
    $noExp = JWT::encode([
        'sub' => '42', 'role' => 'admin', 'jti' => 'no-exp', 'iat' => time(),
    ], TEST_SECRET, 'HS256');

    expect(fn () => $service()->parse($noExp))->toThrow(TokenInvalidException::class);
});

it('refuses to construct without a secret', function () {
    expect(fn () => new TokenService('', 3600, 'HS256'))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses to construct with a secret shorter than the hmac minimum', function () {
    // firebase/php-jwt v7 (the version CVE-2025-45769 forced us onto) throws
    // DomainException for an HMAC key under 32 bytes. Unguarded, a 31-byte
    // secret constructs cleanly and then fails uncaught inside issue().
    expect(fn () => new TokenService(str_repeat('a', 31), 3600, 'HS256'))
        ->toThrow(InvalidArgumentException::class);

    // The matching acceptance side: exactly 32 bytes is allowed.
    expect(new TokenService(str_repeat('a', 32), 3600, 'HS256'))
        ->toBeInstanceOf(TokenService::class);
});

it('refuses to construct with a non-positive ttl', function () {
    // (int) env('JWT_TTL', 3600) turns 'abc' into 0, which would issue tokens
    // already expired at the moment of signing.
    expect(fn () => new TokenService(TEST_SECRET, 0, 'HS256'))
        ->toThrow(InvalidArgumentException::class);
});
