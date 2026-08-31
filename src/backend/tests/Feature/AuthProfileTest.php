<?php

use App\Models\User;

use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeader;

$tokenFor = fn (User $user): string => postJson('/api/v1/auth/login', [
    'email' => $user->email,
    'password' => 'correct-horse-battery',
])->json('token');

it('returns the authenticated user and no password', function () use ($tokenFor) {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);

    $response = withHeader('Authorization', 'Bearer '.$tokenFor($user))
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id);

    expect(json_encode($response->json()))->not->toContain('password');
});

it('updates the caller name and email', function () use ($tokenFor) {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);

    withHeader('Authorization', 'Bearer '.$tokenFor($user))
        ->patchJson('/api/v1/auth/me', ['name' => 'New Name', 'email' => 'new@example.com'])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name')
        ->assertJsonPath('data.email', 'new@example.com');

    expect($user->fresh()->email)->toBe('new@example.com');
});

it('refuses to let a customer promote themselves', function () use ($tokenFor) {
    $user = User::factory()->create(['password' => 'correct-horse-battery']);

    // Both halves: the request succeeds, and the privilege is not granted.
    // Asserting only the 200 would hide a mass-assignment hole.
    withHeader('Authorization', 'Bearer '.$tokenFor($user))
        ->patchJson('/api/v1/auth/me', ['role' => 'admin'])
        ->assertOk();

    expect($user->fresh()->role)->toBe(User::ROLE_CUSTOMER);
});

it('rejects an email already taken by someone else', function () use ($tokenFor) {
    User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create(['password' => 'correct-horse-battery']);

    withHeader('Authorization', 'Bearer '.$tokenFor($user))
        ->patchJson('/api/v1/auth/me', ['email' => 'taken@example.com'])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['email']]]);
});

it('lets the caller keep their own email', function () use ($tokenFor) {
    // The unique rule must ignore the caller's own row — the other side of
    // the boundary above.
    $user = User::factory()->create(['password' => 'correct-horse-battery']);

    withHeader('Authorization', 'Bearer '.$tokenFor($user))
        ->patchJson('/api/v1/auth/me', ['email' => $user->email])
        ->assertOk();
});

it('lowercases a mixed-case email before storing it', function () use ($tokenFor) {
    // Registration normalises; so must this. Stored as submitted, the owner
    // could never log in again: LoginRequest lowercases before the byte-exact
    // lookup in AuthController, so "Ada@Example.com" would match no row.
    $user = User::factory()->create(['password' => 'correct-horse-battery']);

    withHeader('Authorization', 'Bearer '.$tokenFor($user))
        ->patchJson('/api/v1/auth/me', ['email' => 'Ada@Example.COM'])
        ->assertOk()
        ->assertJsonPath('data.email', 'ada@example.com');

    expect($user->fresh()->email)->toBe('ada@example.com');

    // And the normalised address is the one that actually authenticates.
    postJson('/api/v1/auth/login', [
        'email' => 'ada@example.com',
        'password' => 'correct-horse-battery',
    ])->assertOk();
});

it('rejects an email that differs from another account only by capitalisation', function () use ($tokenFor) {
    // The unique index is byte-exact, so without normalisation this creates a
    // second account on one real mailbox.
    User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create(['password' => 'correct-horse-battery']);

    withHeader('Authorization', 'Bearer '.$tokenFor($user))
        ->patchJson('/api/v1/auth/me', ['email' => 'Taken@Example.com'])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['email']]]);
});

it('rejects a password past the 72-byte bcrypt ceiling', function () use ($tokenFor) {
    // Registration enforces FitsBcrypt. If this endpoint does not, an account
    // hardened at signup is downgradeable by its owner in one PATCH: bcrypt
    // reads 72 bytes and silently discards the rest, so every string sharing
    // the first 72 bytes would open the account.
    $user = User::factory()->create(['password' => 'correct-horse-battery']);
    $tooLong = str_repeat('a', 73);

    withHeader('Authorization', 'Bearer '.$tokenFor($user))
        ->patchJson('/api/v1/auth/me', [
            'password' => $tooLong,
            'password_confirmation' => $tooLong,
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['password']]]);
});

it('changes the password and the new one authenticates', function () use ($tokenFor) {
    // The password rule shipped with no coverage at all: removing it entirely
    // left the suite green.
    $user = User::factory()->create(['password' => 'correct-horse-battery']);

    withHeader('Authorization', 'Bearer '.$tokenFor($user))
        ->patchJson('/api/v1/auth/me', [
            'password' => 'a-brand-new-passphrase',
            'password_confirmation' => 'a-brand-new-passphrase',
        ])
        ->assertOk();

    postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'a-brand-new-passphrase',
    ])->assertOk();

    postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'correct-horse-battery',
    ])->assertStatus(401);
});
