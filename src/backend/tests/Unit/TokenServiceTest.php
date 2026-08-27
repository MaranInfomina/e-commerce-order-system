<?php

use App\Exceptions\TokenInvalidException;
use App\Models\User;
use App\Services\TokenService;

$service = fn (): TokenService => new TokenService(
    secret: 'test-secret-at-least-32-characters-long',
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

it('rejects a token signed with a different secret', function () use ($user) {
    $issuedElsewhere = (new TokenService('a-completely-different-secret-value', 3600, 'HS256'))
        ->issue($user())['token'];

    $mine = new TokenService('test-secret-at-least-32-characters-long', 3600, 'HS256');

    expect(fn () => $mine->parse($issuedElsewhere))->toThrow(TokenInvalidException::class);
});

it('rejects an expired token', function () use ($user) {
    // A negative TTL issues a token whose exp is already in the past.
    $expired = (new TokenService('test-secret-at-least-32-characters-long', -10, 'HS256'))
        ->issue($user())['token'];

    $service = new TokenService('test-secret-at-least-32-characters-long', 3600, 'HS256');

    expect(fn () => $service->parse($expired))->toThrow(TokenInvalidException::class);
});

it('rejects a malformed token', function () use ($service) {
    expect(fn () => $service()->parse('not-a-jwt'))->toThrow(TokenInvalidException::class);
});

it('refuses to construct without a secret', function () {
    expect(fn () => new TokenService('', 3600, 'HS256'))
        ->toThrow(InvalidArgumentException::class);
});
