<?php

use App\Models\User;
use App\Services\TokenService;

use function Pest\Laravel\postJson;

it('issues a JWT whose claims identify the user and their role', function () {
    User::factory()->admin()->create([
        'email' => 'admin@example.com',
        'password' => 'correct-horse-battery',
    ]);

    $response = postJson('/api/v1/auth/login', [
        'email' => 'admin@example.com',
        'password' => 'correct-horse-battery',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['token', 'token_type', 'expires_in'])
        ->assertJsonPath('token_type', 'Bearer');

    // Verify it is genuinely a JWT this application can parse, not an
    // opaque string — the checklist says "issues a JWT".
    $claims = app(TokenService::class)->parse($response->json('token'));

    expect($claims['role'])->toBe('admin');
    expect($claims)->toHaveKeys(['sub', 'jti', 'iat', 'exp']);
});

it('rejects a wrong password without revealing whether the email exists', function () {
    User::factory()->create([
        'email' => 'real@example.com',
        'password' => 'correct-horse-battery',
    ]);

    $wrongPassword = postJson('/api/v1/auth/login', [
        'email' => 'real@example.com',
        'password' => 'wrong',
    ]);

    $unknownEmail = postJson('/api/v1/auth/login', [
        'email' => 'nobody@example.com',
        'password' => 'wrong',
    ]);

    // Identical responses: a different status or message here is an
    // account-enumeration oracle.
    $wrongPassword->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
    $unknownEmail->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
    expect($wrongPassword->json())->toBe($unknownEmail->json());
});

it('accepts the correct password for the same account', function () {
    // The other side of the boundary above: without this, a login endpoint
    // that rejected everyone would pass the test before it.
    User::factory()->create([
        'email' => 'real@example.com',
        'password' => 'correct-horse-battery',
    ]);

    postJson('/api/v1/auth/login', [
        'email' => 'real@example.com',
        'password' => 'correct-horse-battery',
    ])->assertOk();
});

it('requires both fields', function () {
    postJson('/api/v1/auth/login', [])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['email', 'password']]]);
});

it('throttles repeated login attempts, and lets the fifth through', function () {
    User::factory()->create([
        'email' => 'real@example.com',
        'password' => 'correct-horse-battery',
    ]);

    $attempt = fn () => postJson('/api/v1/auth/login', [
        'email' => 'real@example.com',
        'password' => 'wrong-password',
    ]);

    // Both sides of the boundary. Attempts 1-5 must reach the controller and
    // fail on credentials (401); only the sixth is refused by the limiter
    // (429). A limiter set to the wrong threshold - or applied to the wrong
    // routes - fails one half or the other.
    foreach (range(1, 5) as $i) {
        $attempt()->assertStatus(401);
    }

    $attempt()
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');
});
