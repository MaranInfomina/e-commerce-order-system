<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;

use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeader;

// A closure, not a top-level function: Pest loads every test file into a
// single process, so a bare `function productWriteTestPayload()` here would
// be a global symbol that any later test file redeclaring the same name
// would fatal on. Scoping it to this file's local variable makes that
// collision structurally impossible rather than merely unlikely.
$productPayload = fn (int $categoryId): array => [
    'category_id' => $categoryId,
    'name' => 'Titanium Kettle',
    'slug' => 'titanium-kettle',
    'sku' => 'KET-0001',
    'description' => 'Boils water quickly.',
    'price_cents' => 12999,
    'stock_quantity' => 10,
    'is_active' => true,
];

// CR-6: Milestone 1 wrote these 15 cases against public write endpoints.
// Milestone 2's FR-17 makes writes admin-only, so each case now acquires an
// admin token. Every original assertion is unchanged — the token is the
// only addition.
$asAdmin = function (): string {
    $admin = User::factory()->admin()->create(['password' => 'correct-horse-battery']);

    $response = postJson('/api/v1/auth/login', [
        'email' => $admin->email,
        'password' => 'correct-horse-battery',
    ]);

    // Assert the login worked before handing back its token. Without this, a
    // 401 or 422 here returns null, the header becomes a bare "Bearer ", and
    // all 15 cases fail as 401 with a diagnosis pointing at product
    // validation — the slowest possible way to find a broken login.
    $response->assertOk();

    return $response->json('token');
};

it('creates a product and stores the price as an integer', function () use ($productPayload, $asAdmin) {
    $token = $asAdmin();
    $category = Category::factory()->create();

    withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/products', $productPayload($category->id))
        ->assertCreated()
        ->assertJsonPath('data.name', 'Titanium Kettle')
        ->assertJsonPath('data.price_cents', 12999);

    $stored = Product::firstWhere('sku', 'KET-0001');

    expect($stored)->not->toBeNull();
    expect($stored->price_cents)->toBeInt()->toBe(12999);
});

it('rejects an invalid payload with per-field details', function () use ($productPayload, $asAdmin) {
    $token = $asAdmin();
    $category = Category::factory()->create();

    $response = withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/products', [
        ...$productPayload($category->id),
        'name' => '',
        'price_cents' => '12.99',
        'category_id' => 999999,
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure([
            'error' => ['code', 'message', 'details' => ['name', 'price_cents', 'category_id']],
        ]);
});

it('rejects a negative stock quantity', function () use ($productPayload, $asAdmin) {
    $token = $asAdmin();
    $category = Category::factory()->create();

    withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/products', [
        ...$productPayload($category->id),
        'stock_quantity' => -1,
    ])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['stock_quantity']]]);
});

it('rejects a price_cents value that overflows the database column', function () use ($productPayload, $asAdmin) {
    // The products.price_cents column is a 4-byte Postgres integer
    // (max 2147483647). Without an upper bound, filter_var-based integer
    // validation happily accepts a PHP int this large and the value only
    // fails once it reaches the database, surfacing as a raw 500 instead
    // of a clean 422.
    $token = $asAdmin();
    $category = Category::factory()->create();

    withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/products', [
        ...$productPayload($category->id),
        'price_cents' => 2147483648,
    ])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['price_cents']]]);
});

it('accepts a price_cents value at the exact database column boundary', function () use ($productPayload, $asAdmin) {
    // Proves max:2147483647 is the bound in force, not a stricter one:
    // the previous test only shows 2147483648 is rejected, which alone
    // cannot distinguish this rule from an accidentally tighter max.
    $token = $asAdmin();
    $category = Category::factory()->create();

    withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/products', [
        ...$productPayload($category->id),
        'price_cents' => 2147483647,
    ])
        ->assertCreated()
        ->assertJsonPath('data.price_cents', 2147483647);
});

it('rejects a stock_quantity value that overflows the database column', function () use ($productPayload, $asAdmin) {
    $token = $asAdmin();
    $category = Category::factory()->create();

    withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/products', [
        ...$productPayload($category->id),
        'stock_quantity' => 2147483648,
    ])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['stock_quantity']]]);
});

it('rejects a duplicate slug or sku', function () use ($productPayload, $asAdmin) {
    $token = $asAdmin();
    $category = Category::factory()->create();
    Product::factory()->for($category)->create(['slug' => 'taken', 'sku' => 'TAKEN-1']);

    withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/products', [
        ...$productPayload($category->id),
        'slug' => 'taken',
    ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['slug']]]);

    withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/products', [
        ...$productPayload($category->id),
        'sku' => 'TAKEN-1',
    ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['sku']]]);
});

it('rejects a slug or sku reused from a soft-deleted product with a clean 422', function () use ($productPayload, $asAdmin) {
    $token = $asAdmin();
    $category = Category::factory()->create();
    $trashed = Product::factory()->for($category)->create(['slug' => 'gone', 'sku' => 'GONE-1']);
    $trashed->delete();

    withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/products', [
        ...$productPayload($category->id),
        'slug' => 'gone',
    ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['slug']]]);

    withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/products', [
        ...$productPayload($category->id),
        'sku' => 'GONE-1',
    ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['sku']]]);
});

it('updates a subset of fields and leaves the rest untouched', function () use ($asAdmin) {
    $token = $asAdmin();
    $product = Product::factory()->create([
        'name' => 'Old Name',
        'price_cents' => 500,
        'stock_quantity' => 3,
    ]);

    withHeader('Authorization', "Bearer {$token}")->patchJson("/api/v1/products/{$product->id}", ['price_cents' => 750])
        ->assertOk()
        ->assertJsonPath('data.price_cents', 750)
        ->assertJsonPath('data.name', 'Old Name')
        ->assertJsonPath('data.stock_quantity', 3);
});

it('lets a product keep its own slug when updating', function () use ($asAdmin) {
    $token = $asAdmin();
    $product = Product::factory()->create(['slug' => 'keeper']);

    withHeader('Authorization', "Bearer {$token}")->patchJson("/api/v1/products/{$product->id}", [
        'slug' => 'keeper',
        'name' => 'Renamed',
    ])->assertOk()->assertJsonPath('data.name', 'Renamed');
});

it('rejects a slug already used by a different product when updating', function () use ($asAdmin) {
    $token = $asAdmin();
    $category = Category::factory()->create();
    Product::factory()->for($category)->create(['slug' => 'someone-elses-slug']);
    $product = Product::factory()->for($category)->create(['slug' => 'mine']);

    withHeader('Authorization', "Bearer {$token}")->patchJson("/api/v1/products/{$product->id}", [
        'slug' => 'someone-elses-slug',
    ])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['slug']]]);

    expect($product->fresh()->slug)->toBe('mine');
});

it('rejects a price_cents value that overflows the database column on update', function () use ($asAdmin) {
    // Proves ProductUpdateRequest carries the same upper bound as
    // ProductStoreRequest; the two classes construct their rule arrays
    // independently so a fix to one does not guarantee the other.
    $token = $asAdmin();
    $product = Product::factory()->create();

    withHeader('Authorization', "Bearer {$token}")->patchJson("/api/v1/products/{$product->id}", [
        'price_cents' => 2147483648,
    ])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['price_cents']]]);
});

it('soft deletes a product and returns no content', function () use ($asAdmin) {
    $token = $asAdmin();
    $product = Product::factory()->create();

    withHeader('Authorization', "Bearer {$token}")->deleteJson("/api/v1/products/{$product->id}")->assertNoContent();

    expect(Product::find($product->id))->toBeNull();
    expect(Product::withTrashed()->find($product->id))->not->toBeNull();
});

it('returns a not-found envelope when updating a product that does not exist', function () use ($asAdmin) {
    $token = $asAdmin();

    withHeader('Authorization', "Bearer {$token}")->patchJson('/api/v1/products/999999', ['name' => 'Ghost'])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');
});

it('returns a not-found envelope when deleting a product that does not exist', function () use ($asAdmin) {
    $token = $asAdmin();

    withHeader('Authorization', "Bearer {$token}")->deleteJson('/api/v1/products/999999')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');
});
