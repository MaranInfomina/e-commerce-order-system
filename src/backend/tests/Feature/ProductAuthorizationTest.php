<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeader;

// Assert the login worked before handing back its token — the same guard
// Step 8's $asAdmin uses. Without it a 401 or 422 here yields null, the
// header becomes a bare "Bearer ", and every case fails as 401 with a
// diagnosis pointing at the policy rather than at the login.
$tokenFor = function (User $user): string {
    $response = postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'correct-horse-battery',
    ]);

    $response->assertOk();

    return $response->json('token');
};

$payload = fn (int $categoryId): array => [
    'category_id' => $categoryId,
    'name' => 'Policy Probe',
    'slug' => 'policy-probe',
    'sku' => 'POL-0001',
    'description' => 'For authorization tests.',
    'price_cents' => 1000,
    'stock_quantity' => 1,
    'is_active' => true,
];

it('keeps product reads public', function () {
    $product = Product::factory()->create();

    // No Authorization header at all — FR-18.
    getJson('/api/v1/products')->assertOk();
    getJson("/api/v1/products/{$product->id}")->assertOk();
    getJson('/api/v1/categories')->assertOk();
});

it('rejects a customer token on every write', function () use ($tokenFor, $payload) {
    $customer = User::factory()->create(['password' => 'correct-horse-battery']);
    $token = $tokenFor($customer);
    $category = Category::factory()->create();
    $product = Product::factory()->create();

    withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/products', $payload($category->id))
        ->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');

    withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/products/{$product->id}", ['name' => 'Renamed'])
        ->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');

    withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/products/{$product->id}")
        ->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
});

it('denies a customer before validation runs, so a rejected write leaks no field detail', function () use ($tokenFor) {
    // The only test that can see the form-request authorize() methods. Every
    // other case here sends a VALID body, so the controller's own authorize()
    // produces the same 403 — meaning both ProductStoreRequest::authorize()
    // and ProductUpdateRequest::authorize() could be deleted with the suite
    // still green, reopening the enumeration oracle they exist to close.
    //
    // A FormRequest authorizes before it validates. A non-admin must get 403,
    // never a 422 whose details confirm which slug or sku is already taken.
    $customer = User::factory()->create(['password' => 'correct-horse-battery']);
    $token = $tokenFor($customer);
    $product = Product::factory()->create(['slug' => 'taken-slug']);

    // No body at all: six required fields would fail if validation ran first.
    withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/products', [])
        ->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');

    // The oracle itself: a body that would trip unique:products,slug.
    withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/products', ['slug' => 'taken-slug'])
        ->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');

    // The same one layer up. ProductUpdateRequest::authorize() must deny
    // before rules() evaluates Rule::unique()->ignore() for this product.
    withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/products/{$product->id}", ['price_cents' => 'not-an-integer'])
        ->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
});

it('accepts an admin token on every write', function () use ($tokenFor, $payload) {
    // The other side of the boundary. Without this, a policy that denied
    // everyone would satisfy the test above.
    $admin = User::factory()->admin()->create(['password' => 'correct-horse-battery']);
    $token = $tokenFor($admin);
    $category = Category::factory()->create();
    $product = Product::factory()->create();

    withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/products', $payload($category->id))
        ->assertCreated();

    withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/products/{$product->id}", ['name' => 'Renamed'])
        ->assertOk();

    withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/products/{$product->id}")
        ->assertNoContent();
});

it('rejects an unauthenticated write with 401, not 403', function () use ($payload) {
    // 401 means "who are you"; 403 means "not you". Returning 403 to an
    // anonymous caller confirms the endpoint exists and is admin-only.
    //
    // All three verbs, not just POST: the customer-token test above covers
    // three routes, so checking only one of them here leaves PATCH and DELETE
    // with no anonymous coverage at all. A route left outside the auth:api
    // group in Step 7 would be caught for POST and missed for the other two.
    $category = Category::factory()->create();
    $product = Product::factory()->create();

    postJson('/api/v1/products', $payload($category->id))
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');

    patchJson("/api/v1/products/{$product->id}", ['name' => 'Renamed'])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');

    deleteJson("/api/v1/products/{$product->id}")
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

it('reads the role from the database, not the token claim', function () use ($tokenFor, $payload) {
    // An admin logs in, then is demoted. The old token still carries
    // role=admin, so authorization must refuse it immediately rather than
    // waiting for the token to expire (spec section 3.5).
    $user = User::factory()->admin()->create(['password' => 'correct-horse-battery']);
    $token = $tokenFor($user);
    $category = Category::factory()->create();

    $user->update(['role' => User::ROLE_CUSTOMER]);

    withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/products', $payload($category->id))
        ->assertStatus(403);

    // The matching half. Without it, a bug that invalidated the whole token on
    // any user update would also produce 403 here and pass — proving nothing
    // about where the role was read from. This asserts the token is still
    // perfectly valid; only the authorization decision changed.
    withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.role', User::ROLE_CUSTOMER);
});

it('honours a promotion from the database too, not just a demotion', function () use ($tokenFor, $payload) {
    // The other direction of spec section 3.5. A customer logs in, so the
    // token carries role=customer forever, then is promoted. Authorization
    // must allow the write immediately rather than making them log in again.
    // Testing only the demotion direction would pass for an implementation
    // that simply denied everyone holding a stale claim.
    $user = User::factory()->create(['password' => 'correct-horse-battery']);
    $token = $tokenFor($user);
    $category = Category::factory()->create();

    $user->update(['role' => User::ROLE_ADMIN]);

    withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/products', $payload($category->id))
        ->assertCreated();
});
