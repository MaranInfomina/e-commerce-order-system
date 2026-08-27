<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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

it('rejects a role the database does not allow', function (string $role) {
    // Raw insert bypasses the model, proving the constraint is enforced by
    // the database and not merely by application code.
    //
    // Several values, not one. A single sentinel only proves that ONE string
    // is banned: a constraint written as CHECK (role <> 'superuser') is a
    // blacklist that still accepts root, owner, '' and every future typo,
    // and it would satisfy a one-value test while leaving the column open.
    // These four probe different escapes - an ordinary unlisted word, an
    // administrative synonym, a case variant of a value that IS allowed, and
    // the empty string - so only a genuine whitelist passes all of them.
    expect(fn () => DB::table('users')->insert([
        'name' => 'Mallory',
        'email' => Str::slug($role ?: 'empty').'@example.com',
        'password' => 'x',
        'role' => $role,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
})->with(['superuser', 'root', 'Admin', '']);

it('accepts both allowed roles through a raw insert', function (string $role) {
    // The matching acceptance side, also bypassing the model. Without it a
    // constraint of CHECK (false) - or one that dropped 'admin' - would pass
    // every rejection case above while breaking the application entirely.
    DB::table('users')->insert([
        'name' => 'Allowed',
        'email' => $role.'@example.com',
        'password' => 'x',
        'role' => $role,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('users')->where('email', $role.'@example.com')->value('role'))->toBe($role);
})->with([User::ROLE_CUSTOMER, User::ROLE_ADMIN]);

it('rejects a duplicate email', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    expect(fn () => User::factory()->create(['email' => 'taken@example.com']))
        ->toThrow(QueryException::class);
});
