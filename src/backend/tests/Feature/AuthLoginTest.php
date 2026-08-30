<?php

use App\Models\User;
use App\Services\TokenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

use function Pest\Laravel\postJson;

it('issues a JWT whose claims identify the user and their role', function () {
    // A decoy created first, so `sub` cannot be right by accident. An
    // implementation that minted the claim for User::first() — or for a
    // hardcoded id — would pass an existence-only assertion and hand every
    // caller the wrong identity. Tasks 5 and 6 resolve `sub` to a row and
    // authorize against it, so a wrong `sub` is a full account takeover.
    User::factory()->create(['email' => 'decoy@example.com']);

    $admin = User::factory()->admin()->create([
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
    expect($claims['sub'])->toBe((string) $admin->id);
    expect($claims['sub'])->not->toBe((string) User::orderBy('id')->first()->id);
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

it('performs the password comparison on the unknown-email path as well as the known one', function () {
    // The headline security property of this endpoint, asserted on the
    // mechanism rather than the clock: phpunit.xml forces BCRYPT_ROUNDS=4, so
    // the two paths differ by microseconds in-suite and no wall-clock
    // assertion could separate them without being flaky. If Hash::check() ran
    // on both paths, the work was done on both paths.
    User::factory()->create([
        'email' => 'real@example.com',
        'password' => 'correct-horse-battery',
    ]);

    $hashing = recordHashChecks();

    postJson('/api/v1/auth/login', [
        'email' => 'real@example.com',
        'password' => 'wrong-password',
    ])->assertStatus(401);

    expect($hashing->compared)->toHaveCount(1);
    $knownEmailDigest = $hashing->compared[0];

    postJson('/api/v1/auth/login', [
        'email' => 'nobody@example.com',
        'password' => 'wrong-password',
    ])->assertStatus(401);

    // Reverting to `$user === null || ! Hash::check(...)` short-circuits and
    // never reaches the comparison here, leaving this at 1.
    expect($hashing->compared)->toHaveCount(2);

    $unknownEmailDigest = $hashing->compared[1];

    // And the unknown-email path compared against a genuine digest, not ''
    // or a literal the hasher rejects — either returns instantly and restores
    // exactly the timing difference the comparison exists to erase.
    expect($unknownEmailDigest)->not->toBe($knownEmailDigest);
    expect(Hash::isHashed($unknownEmailDigest))->toBeTrue();
});

it('derives the placeholder digest from the configured hasher, not from a frozen bcrypt literal', function () {
    // HASH_DRIVER is an operator-settable environment variable that nothing in
    // this project pins. A hardcoded bcrypt placeholder is only correct while
    // bcrypt is the driver: ArgonHasher::check() throws on a bcrypt digest, so
    // every unknown email would 500 while every known email still returned
    // 401 — the enumeration oracle back again, reintroduced by configuration
    // alone.
    //
    // Argon parameters are dialled right down for the same reason phpunit.xml
    // forces BCRYPT_ROUNDS=4: the algorithm is under test, not its cost.
    config([
        'hashing.driver' => 'argon2id',
        'hashing.argon' => ['memory' => 1024, 'threads' => 1, 'time' => 1, 'verify' => true],
    ]);

    User::factory()->create([
        'email' => 'real@example.com',
        'password' => 'correct-horse-battery',
    ]);

    $hashing = recordHashChecks();

    $wrongPassword = postJson('/api/v1/auth/login', [
        'email' => 'real@example.com',
        'password' => 'wrong',
    ]);

    $unknownEmail = postJson('/api/v1/auth/login', [
        'email' => 'nobody@example.com',
        'password' => 'wrong',
    ]);

    $wrongPassword->assertStatus(401);
    $unknownEmail->assertStatus(401);
    expect($unknownEmail->json())->toBe($wrongPassword->json());

    // The status codes alone are not enough here, and that is the whole
    // point of asserting further. login() catches a throwing comparison and
    // fails closed, so a placeholder the hasher cannot parse still answers
    // 401 — it just answers it *instantly*, without comparing anything,
    // which is the timing oracle wearing a different hat. So assert on the
    // digest itself: the configured hasher must be able to verify it.
    expect($hashing->compared)->toHaveCount(2);

    $placeholder = $hashing->compared[1];

    expect(Hash::isHashed($placeholder))->toBeTrue();
    expect(Hash::check('nothing hashes to this', $placeholder))->toBeFalse();
});

it('answers a login against an unparseable stored password with the same 401 as an unknown email', function () {
    // Proved live before this test existed: a row whose password column is not
    // a digest the hasher can parse made Hash::check() throw, and the uncaught
    // throw became a 500 — while an unknown email stayed 401. Two different
    // status codes for two different account states is precisely the signal
    // the single failure branch exists to suppress.
    $user = User::factory()->create(['email' => 'corrupt@example.com']);

    // Straight through the query builder: the model's `hashed` cast would
    // re-hash the value and there would be nothing malformed to test.
    DB::table('users')->where('id', $user->id)->update(['password' => 'not-a-bcrypt-digest']);

    // The controller logs a corrupted row on purpose; the spy keeps that
    // (correct) write out of the suite's output.
    Log::spy();

    $corrupt = postJson('/api/v1/auth/login', [
        'email' => 'corrupt@example.com',
        'password' => 'correct-horse-battery',
    ]);

    $unknownEmail = postJson('/api/v1/auth/login', [
        'email' => 'nobody@example.com',
        'password' => 'correct-horse-battery',
    ]);

    $corrupt->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
    expect($corrupt->json())->toBe($unknownEmail->json());
});

it('still runs a real comparison when a stored password is an empty string', function () {
    // The status code cannot catch this one, which is why it is asserted on
    // the mechanism instead. Hash::check() returns false for an empty hash
    // *without comparing anything*, so an empty-password row answers 401 in
    // microseconds while every real account pays a full bcrypt — same status,
    // same body, still an enumeration oracle. Substituting the placeholder is
    // what closes it, and `$user?->password ?? $placeholder` does not: '' is
    // not null, so it never reaches the fallback.
    $user = User::factory()->create(['email' => 'blank@example.com']);

    DB::table('users')->where('id', $user->id)->update(['password' => '']);

    $hashing = recordHashChecks();

    postJson('/api/v1/auth/login', [
        'email' => 'blank@example.com',
        'password' => 'correct-horse-battery',
    ])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');

    expect($hashing->compared)->toHaveCount(1);
    expect($hashing->compared[0])->not->toBe('');
    expect(Hash::isHashed($hashing->compared[0]))->toBeTrue();
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

it('treats an email address as case-insensitive', function () {
    // One mailbox is one account. Postgres compares strings byte-exactly, so
    // without normalisation at the edge the account created as
    // ada@example.com simply cannot be logged into as Ada@Example.com.
    User::factory()->create([
        'email' => 'ada@example.com',
        'password' => 'correct-horse-battery',
    ]);

    postJson('/api/v1/auth/login', [
        'email' => 'Ada@Example.COM',
        'password' => 'correct-horse-battery',
    ])->assertOk();
});

it('rejects a password longer than bcrypt can hash', function () {
    // bcrypt reads 72 bytes and discards the rest, so an unbounded field lets
    // two different passwords open the same account — and lets a caller make
    // the server hash a megabyte for free.
    postJson('/api/v1/auth/login', [
        'email' => 'real@example.com',
        'password' => str_repeat('a', 73),
    ])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['password']]]);
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

it('throttles by client address, not by the email being attempted', function () {
    // The test above reuses one email for all six attempts, so a per-email
    // limiter would pass it identically — and a per-email limiter is no
    // defence at all against credential stuffing, which by definition tries
    // one password against thousands of different accounts. Six distinct
    // emails from one address must still trip on the sixth.
    foreach (range(1, 5) as $i) {
        postJson('/api/v1/auth/login', [
            'email' => "victim{$i}@example.com",
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    postJson('/api/v1/auth/login', [
        'email' => 'victim6@example.com',
        'password' => 'wrong-password',
    ])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');
});

it('keeps the rate-limit headers on the 429 envelope', function () {
    // ApiExceptionRenderer builds a fresh JsonResponse, which drops whatever
    // headers the exception carried. Retry-After is the only thing telling a
    // client when it may try again — Task 10's login page shows it — and
    // without these a locked-out visitor can only poll blindly.
    $attempt = fn () => postJson('/api/v1/auth/login', [
        'email' => 'real@example.com',
        'password' => 'wrong-password',
    ]);

    foreach (range(1, 5) as $i) {
        $attempt();
    }

    $attempt()
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertHeader('X-RateLimit-Limit', '5')
        ->assertHeader('X-RateLimit-Remaining', '0')
        ->assertHeader('X-RateLimit-Reset')
        // The envelope itself must survive the header merge.
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');
});

it('does not spend the registration allowance on failed logins', function () {
    // Laravel's inline `throttle:5,1` keys on domain + IP with no route path,
    // so both endpoints shared one bucket: five wrong passwords also locked
    // the visitor out of creating an account. Named limiters give each route
    // its own counter.
    $attempt = fn () => postJson('/api/v1/auth/login', [
        'email' => 'real@example.com',
        'password' => 'wrong-password',
    ]);

    foreach (range(1, 5) as $i) {
        $attempt();
    }

    $attempt()->assertStatus(429);

    postJson('/api/v1/auth/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertCreated();
});
