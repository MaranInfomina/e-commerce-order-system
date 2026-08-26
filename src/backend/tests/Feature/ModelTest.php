<?php

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('relates a product to its category in both directions', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->for($category)->create();

    expect($product->category->id)->toBe($category->id);
    expect($category->products->pluck('id')->all())->toBe([$product->id]);
});

it('casts price and stock to integers and active to boolean', function () {
    $product = Product::factory()->create([
        'price_cents' => 1999,
        'stock_quantity' => 7,
        'is_active' => true,
    ]);

    $fresh = $product->fresh();

    expect($fresh->price_cents)->toBeInt()->toBe(1999);
    expect($fresh->stock_quantity)->toBeInt()->toBe(7);
    expect($fresh->is_active)->toBeBool()->toBeTrue();
});

it('soft deletes products rather than removing the row', function () {
    $product = Product::factory()->create();

    $product->delete();

    expect(Product::find($product->id))->toBeNull();
    expect(Product::withTrashed()->find($product->id))->not->toBeNull();
});
