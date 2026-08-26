<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates the categories and products tables', function () {
    expect(Schema::hasTable('categories'))->toBeTrue();
    expect(Schema::hasTable('products'))->toBeTrue();

    expect(Schema::hasColumns('products', [
        'id', 'category_id', 'name', 'slug', 'sku', 'description',
        'price_cents', 'stock_quantity', 'is_active',
        'created_at', 'updated_at', 'deleted_at',
    ]))->toBeTrue();
});

it('rejects negative stock at the database level', function () {
    $categoryId = DB::table('categories')->insertGetId([
        'name' => 'Test', 'slug' => 'test',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => DB::table('products')->insert([
        'category_id' => $categoryId,
        'name' => 'Negative stock',
        'slug' => 'negative-stock',
        'sku' => 'NEG-001',
        'price_cents' => 1000,
        'stock_quantity' => -1,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('prevents deleting a category that still has products', function () {
    $categoryId = DB::table('categories')->insertGetId([
        'name' => 'Occupied', 'slug' => 'occupied',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('products')->insert([
        'category_id' => $categoryId,
        'name' => 'Held', 'slug' => 'held', 'sku' => 'HELD-001',
        'price_cents' => 500, 'stock_quantity' => 1, 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => DB::table('categories')->where('id', $categoryId)->delete())
        ->toThrow(QueryException::class);
});
