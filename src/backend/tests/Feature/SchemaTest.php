<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates the categories and products tables', function () {
    expect(Schema::hasTable('categories'))->toBeTrue();
    expect(Schema::hasTable('products'))->toBeTrue();

    expect(Schema::hasColumns('products', [
        'id', 'category_id', 'name', 'slug', 'sku', 'description',
        'price_cents', 'stock_quantity', 'is_active',
        'created_at', 'updated_at', 'deleted_at',
    ]))->toBeTrue();

    // DEC-8/NFR-7: money is the integer column price_cents — never float or decimal.
    $priceColumn = collect(Schema::getColumns('products'))->firstWhere('name', 'price_cents');
    expect($priceColumn['type'])->toBe('integer');
});

it('has exactly the tables Milestone 2 allows and no more', function () {
    // DEC-5 as amended by Milestone 2's CR-5: categories, products, and now
    // users. No Sanctum, so no personal_access_tokens. Query the catalog
    // directly rather than listing expectations that would pass vacuously —
    // this is what makes the constraint an enforced invariant, not prose.
    $tables = DB::table('information_schema.tables')
        ->where('table_schema', 'public')
        ->where('table_type', 'BASE TABLE')
        ->orderBy('table_name')
        ->pluck('table_name')
        ->all();

    expect($tables)->toBe(['categories', 'migrations', 'products', 'users']);
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

it('rejects negative price at the database level', function () {
    $categoryId = DB::table('categories')->insertGetId([
        'name' => 'Test', 'slug' => 'test',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => DB::table('products')->insert([
        'category_id' => $categoryId,
        'name' => 'Negative price',
        'slug' => 'negative-price',
        'sku' => 'NEG-002',
        'price_cents' => -1,
        'stock_quantity' => 1,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('accepts zero stock and zero price as the boundary of both constraints', function () {
    $categoryId = DB::table('categories')->insertGetId([
        'name' => 'Test', 'slug' => 'test',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $id = DB::table('products')->insertGetId([
        'category_id' => $categoryId,
        'name' => 'Zero boundary',
        'slug' => 'zero-boundary',
        'sku' => 'ZERO-001',
        'price_cents' => 0,
        'stock_quantity' => 0,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('products')->where('id', $id)->exists())->toBeTrue();
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
