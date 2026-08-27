<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

it('stores the password as a bcrypt hash, never in plain text', function () {
    $user = User::factory()->create(['password' => 'secret-password']);

    expect($user->password)->not->toBe('secret-password');
    expect(Hash::check('secret-password', $user->password))->toBeTrue();
});

it('hides the password from array and json serialization', function () {
    $user = User::factory()->create();

    expect($user->toArray())->not->toHaveKey('password');
    expect(json_decode($user->toJson(), true))->not->toHaveKey('password');
});

it('defaults a new user to the customer role', function () {
    $user = User::factory()->create();

    expect($user->role)->toBe(User::ROLE_CUSTOMER);
    expect($user->isAdmin())->toBeFalse();
});

it('reports an admin as an admin', function () {
    expect(User::factory()->admin()->create()->isAdmin())->toBeTrue();
});

it('rejects a role the database does not allow', function () {
    // Raw insert bypasses the model, proving the constraint is enforced by
    // the database and not merely by application code.
    expect(fn () => DB::table('users')->insert([
        'name' => 'Mallory',
        'email' => 'mallory@example.com',
        'password' => 'x',
        'role' => 'superuser',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a duplicate email', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    expect(fn () => User::factory()->create(['email' => 'taken@example.com']))
        ->toThrow(QueryException::class);
});
