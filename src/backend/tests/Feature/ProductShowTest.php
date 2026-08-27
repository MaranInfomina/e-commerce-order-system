<?php

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

it('returns a single product with its category', function () {
    $category = Category::factory()->create(['slug' => 'kitchen']);
    $product = Product::factory()->for($category)->create([
        'name' => 'Titanium Kettle',
        'price_cents' => 12999,
        'stock_quantity' => 4,
    ]);

    getJson("/api/v1/products/{$product->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $product->id)
        ->assertJsonPath('data.name', 'Titanium Kettle')
        ->assertJsonPath('data.price_cents', 12999)
        ->assertJsonPath('data.stock_quantity', 4)
        ->assertJsonPath('data.category.slug', 'kitchen');
});

it('distinguishes a missing record from a missing route', function () {
    getJson('/api/v1/products/999999')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    getJson('/api/v1/prodcuts/1')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'ROUTE_NOT_FOUND');
});

it('does not return a soft-deleted product', function () {
    $product = Product::factory()->create();
    $product->delete();

    getJson("/api/v1/products/{$product->id}")
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');
});
