<?php

use App\Models\Category;
use App\Models\Product;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

function validProductPayload(int $categoryId): array
{
    return [
        'category_id' => $categoryId,
        'name' => 'Titanium Kettle',
        'slug' => 'titanium-kettle',
        'sku' => 'KET-0001',
        'description' => 'Boils water quickly.',
        'price_cents' => 12999,
        'stock_quantity' => 10,
        'is_active' => true,
    ];
}

it('creates a product and stores the price as an integer', function () {
    $category = Category::factory()->create();

    postJson('/api/v1/products', validProductPayload($category->id))
        ->assertCreated()
        ->assertJsonPath('data.name', 'Titanium Kettle')
        ->assertJsonPath('data.price_cents', 12999);

    $stored = Product::firstWhere('sku', 'KET-0001');

    expect($stored)->not->toBeNull();
    expect($stored->price_cents)->toBeInt()->toBe(12999);
});

it('rejects an invalid payload with per-field details', function () {
    $category = Category::factory()->create();

    $response = postJson('/api/v1/products', [
        ...validProductPayload($category->id),
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

it('rejects a negative stock quantity', function () {
    $category = Category::factory()->create();

    postJson('/api/v1/products', [
        ...validProductPayload($category->id),
        'stock_quantity' => -1,
    ])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['stock_quantity']]]);
});

it('rejects a duplicate slug or sku', function () {
    $category = Category::factory()->create();
    Product::factory()->for($category)->create(['slug' => 'taken', 'sku' => 'TAKEN-1']);

    postJson('/api/v1/products', [
        ...validProductPayload($category->id),
        'slug' => 'taken',
    ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['slug']]]);

    postJson('/api/v1/products', [
        ...validProductPayload($category->id),
        'sku' => 'TAKEN-1',
    ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['sku']]]);
});

it('rejects a slug or sku reused from a soft-deleted product with a clean 422', function () {
    $category = Category::factory()->create();
    $trashed = Product::factory()->for($category)->create(['slug' => 'gone', 'sku' => 'GONE-1']);
    $trashed->delete();

    postJson('/api/v1/products', [
        ...validProductPayload($category->id),
        'slug' => 'gone',
    ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['slug']]]);

    postJson('/api/v1/products', [
        ...validProductPayload($category->id),
        'sku' => 'GONE-1',
    ])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['sku']]]);
});

it('updates a subset of fields and leaves the rest untouched', function () {
    $product = Product::factory()->create([
        'name' => 'Old Name',
        'price_cents' => 500,
        'stock_quantity' => 3,
    ]);

    patchJson("/api/v1/products/{$product->id}", ['price_cents' => 750])
        ->assertOk()
        ->assertJsonPath('data.price_cents', 750)
        ->assertJsonPath('data.name', 'Old Name')
        ->assertJsonPath('data.stock_quantity', 3);
});

it('lets a product keep its own slug when updating', function () {
    $product = Product::factory()->create(['slug' => 'keeper']);

    patchJson("/api/v1/products/{$product->id}", [
        'slug' => 'keeper',
        'name' => 'Renamed',
    ])->assertOk()->assertJsonPath('data.name', 'Renamed');
});

it('soft deletes a product and returns no content', function () {
    $product = Product::factory()->create();

    deleteJson("/api/v1/products/{$product->id}")->assertNoContent();

    expect(Product::find($product->id))->toBeNull();
    expect(Product::withTrashed()->find($product->id))->not->toBeNull();
});
