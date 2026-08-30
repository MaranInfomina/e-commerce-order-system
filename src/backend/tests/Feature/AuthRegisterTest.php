<?php

use App\Models\User;

use function Pest\Laravel\postJson;

it('registers a visitor as a customer and never returns the password', function () {
    $response = postJson('/api/v1/auth/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.email', 'ada@example.com')
        ->assertJsonPath('data.role', 'customer');

    // NFR-10, asserted on the wire rather than on the model.
    expect(json_encode($response->json()))->not->toContain('password');

    expect(User::where('email', 'ada@example.com')->exists())->toBeTrue();
});

it('rejects a duplicate email with a field-level error', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    postJson('/api/v1/auth/register', [
        'name' => 'Impostor',
        'email' => 'taken@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => ['email']]]);
});

it('rejects a mismatched password confirmation', function () {
    postJson('/api/v1/auth/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'something-else',
    ])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['password']]]);
});

it('refuses to let a registrant choose their own role', function () {
    // Both directions: the request is accepted, and the privilege is not
    // granted. Asserting only the 201 would miss a mass-assignment hole.
    postJson('/api/v1/auth/register', [
        'name' => 'Mallory',
        'email' => 'mallory@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
        'role' => 'admin',
    ])->assertCreated();

    expect(User::where('email', 'mallory@example.com')->first()->role)
        ->toBe(User::ROLE_CUSTOMER);
});
