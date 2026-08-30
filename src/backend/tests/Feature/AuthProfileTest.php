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
