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

    expect($tables)->toBe([
        'categories', 'failed_jobs', 'migrations', 'order_items',
        'order_status_history', 'orders', 'products', 'users',
    ]);
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

it('rejects an out-of-enum order status at the database level', function () {
    $user = DB::table('users')->insertGetId([
        'name' => 'Test', 'email' => 'schema-order-status@example.com',
        'password' => bcrypt('password'), 'role' => 'customer',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => DB::table('orders')->insert([
        'user_id' => $user,
        'status' => 'not_a_real_status',
        'shipping_address' => '1 Test Street',
        'total_cents' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a negative order total at the database level', function () {
    $user = DB::table('users')->insertGetId([
        'name' => 'Test', 'email' => 'schema-order-total@example.com',
        'password' => bcrypt('password'), 'role' => 'customer',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => DB::table('orders')->insert([
        'user_id' => $user,
        'status' => 'pending',
        'shipping_address' => '1 Test Street',
        'total_cents' => -1,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a duplicate idempotency key for the same user but allows it for a different user', function () {
    $alice = DB::table('users')->insertGetId([
        'name' => 'Alice', 'email' => 'schema-idem-alice@example.com',
        'password' => bcrypt('password'), 'role' => 'customer',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $bob = DB::table('users')->insertGetId([
        'name' => 'Bob', 'email' => 'schema-idem-bob@example.com',
        'password' => bcrypt('password'), 'role' => 'customer',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $row = fn (int $userId) => [
        'user_id' => $userId, 'status' => 'pending', 'idempotency_key' => 'shared-key',
        'shipping_address' => '1 Test Street', 'total_cents' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ];

    DB::table('orders')->insert($row($alice));

    // Wrapped in its own transaction: Postgres aborts the entire enclosing
    // transaction on a query error, and RefreshDatabase already has this
    // whole test inside one. Without this, the aborted-transaction state
    // would poison Bob's insert below too. DB::transaction() opens a
    // SAVEPOINT here (it detects it is already inside a transaction) and
    // rolls back only to that savepoint when the exception propagates.
    expect(fn () => DB::transaction(fn () => DB::table('orders')->insert($row($alice))))
        ->toThrow(QueryException::class);
    // Different user, same key: must NOT throw.
    DB::table('orders')->insert($row($bob));

    expect(DB::table('orders')->where('idempotency_key', 'shared-key')->count())->toBe(2);
});

it('rejects a zero or negative order item quantity at the database level', function () {
    $categoryId = DB::table('categories')->insertGetId([
        'name' => 'Test', 'slug' => 'schema-order-items-test',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $productId = DB::table('products')->insertGetId([
        'category_id' => $categoryId, 'name' => 'Test Product', 'slug' => 'schema-oi-product',
        'sku' => 'SCH-001', 'price_cents' => 100, 'stock_quantity' => 10, 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $orderId = DB::table('orders')->insertGetId([
        'user_id' => DB::table('users')->insertGetId([
            'name' => 'Test', 'email' => 'schema-oi-user@example.com',
            'password' => bcrypt('password'), 'role' => 'customer',
            'created_at' => now(), 'updated_at' => now(),
        ]),
        'status' => 'pending', 'shipping_address' => '1 Test Street', 'total_cents' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => DB::table('order_items')->insert([
        'order_id' => $orderId, 'product_id' => $productId,
        'product_name' => 'Test Product', 'product_sku' => 'SCH-001',
        'unit_price_cents' => 100, 'quantity' => 0, 'line_total_cents' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
