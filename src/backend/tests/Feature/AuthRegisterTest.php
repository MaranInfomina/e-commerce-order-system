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

it('stores the email lowercased, so one mailbox cannot become two accounts', function () {
    // Without normalisation this registered cleanly beside an existing
    // ada@example.com: two rows, one real mailbox, and two different owners
    // for every order, cart and permission Tasks 5-9 hang off user identity.
    User::factory()->create(['email' => 'ada@example.com']);

    postJson('/api/v1/auth/register', [
        'name' => 'Impostor',
        'email' => 'Ada@Example.COM',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['email']]]);

    // And the other side of it: a mixed-case address that collides with
    // nothing is accepted, and stored folded.
    postJson('/api/v1/auth/register', [
        'name' => 'Grace Hopper',
        'email' => 'Grace@Example.COM',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])
        ->assertCreated()
        ->assertJsonPath('data.email', 'grace@example.com');

    expect(User::where('email', 'grace@example.com')->exists())->toBeTrue();
    expect(User::count())->toBe(2);
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

it('enforces the twelve-character password minimum on both sides of the boundary', function () {
    // One assertion alone cannot catch a weakened rule: `min:1` still rejects
    // nothing and `min:99` still accepts nothing. Only the pair pins the
    // threshold to exactly twelve.
    $register = fn (string $email, string $password) => postJson('/api/v1/auth/register', [
        'name' => 'Ada Lovelace',
        'email' => $email,
        'password' => $password,
        'password_confirmation' => $password,
    ]);

    $register('short@example.com', str_repeat('a', 11))
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['password']]]);

    $register('long-enough@example.com', str_repeat('a', 12))
        ->assertCreated();
});

it('enforces bcrypt 72-byte ceiling on both sides of the boundary', function () {
    // bcrypt hashes the first 72 bytes and silently discards the rest, so
    // under the old `max:255` two passwords sharing their first 72 bytes both
    // opened the same account.
    $register = fn (string $email, string $password) => postJson('/api/v1/auth/register', [
        'name' => 'Ada Lovelace',
        'email' => $email,
        'password' => $password,
        'password_confirmation' => $password,
    ]);

    $register('too-long@example.com', str_repeat('a', 73))
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['password']]]);

    $register('exactly-72@example.com', str_repeat('a', 72))
        ->assertCreated();
});

it('measures the password ceiling in bytes, not characters', function () {
    // Laravel's `max` rule counts with mb_strlen, so `max:72` would happily
    // accept this 24-character, 72-byte-plus password. It is 25 three-byte
    // characters — 75 bytes — and bcrypt would drop the tail.
    postJson('/api/v1/auth/register', [
        'name' => 'Ada Lovelace',
        'email' => 'multibyte@example.com',
        'password' => str_repeat('あ', 25),
        'password_confirmation' => str_repeat('あ', 25),
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

it('throttles repeated registration attempts, and lets the fifth through', function () {
    // Registration was covered by the shared limiter but never tested, so
    // deleting the middleware from this route alone left the suite green —
    // and left the one endpoint that writes rows and runs a bcrypt per call
    // open to unlimited automated signup.
    $attempt = fn (int $i) => postJson('/api/v1/auth/register', [
        'name' => 'Ada Lovelace',
        'email' => "visitor{$i}@example.com",
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ]);

    foreach (range(1, 5) as $i) {
        $attempt($i)->assertCreated();
    }

    $attempt(6)
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');

    // The sixth was refused before the controller ran, not after.
    expect(User::count())->toBe(5);
});

it('does not spend the login allowance on registrations', function () {
    // The mirror of the login-side test: with one shared bucket, five
    // registrations locked the same visitor out of signing in.
    $user = User::factory()->create([
        'email' => 'real@example.com',
        'password' => 'correct-horse-battery',
    ]);

    foreach (range(1, 5) as $i) {
        postJson('/api/v1/auth/register', [
            'name' => 'Ada Lovelace',
            'email' => "visitor{$i}@example.com",
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ])->assertCreated();
    }

    postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'correct-horse-battery',
    ])->assertOk();
});
