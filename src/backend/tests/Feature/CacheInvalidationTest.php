<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeader;

// No bare `patchJson` import: every PATCH below is chained off withHeader(),
// so importing the free function leaves it unused and Pint's
// no_unused_imports fails the build. Same reason Task 6 had to drop
// deleteJson and patchJson from ProductWriteTest.

$adminToken = function (): string {
    $admin = User::factory()->admin()->create(['password' => 'correct-horse-battery']);

    $response = postJson('/api/v1/auth/login', [
        'email' => $admin->email,
        'password' => 'correct-horse-battery',
    ]);

    // Assert the login worked before handing back its token — the guard Task 6
    // established. Without it a 401 here yields null, the header becomes a
    // bare "Bearer ", and all three invalidation tests fail as 401 with a
    // diagnosis pointing at the cache instead of at auth.
    $response->assertOk();

    return $response->json('token');
};

it('caches a single product read with a bounded ttl', function () {
    $product = Product::factory()->create();

    expect(Cache::has("product:{$product->id}"))->toBeFalse();

    getJson("/api/v1/products/{$product->id}")->assertOk();

    expect(Cache::has("product:{$product->id}"))->toBeTrue();

    // A TTL must actually be set — a cache entry that never expires is a
    // memory leak, and the checklist asks for a TTL specifically.
    // The `cache` connection, bare key. `Cache::getStore()->getRedis()`
    // returns the RedisManager, whose ->ttl() proxies to the DEFAULT
    // connection — which is carts and the denylist, not the cache, so it
    // would read the wrong database and report -2.
    $ttl = Redis::connection('cache')->ttl("product:{$product->id}");

    expect($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(3600);
});

it('serves the second read without touching the database', function () {
    $product = Product::factory()->create();

    getJson("/api/v1/products/{$product->id}")->assertOk();

    DB::enableQueryLog();
    getJson("/api/v1/products/{$product->id}")->assertOk();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // The cache hit is the point: no product select on the second read.
    //
    // This assertion is only meaningful because Step 5 removes implicit route
    // model binding from `show`. If someone restores `show(Product $product)`,
    // Laravel resolves the model before the controller runs, this filter
    // matches on every request, and the test fails — which is the correct and
    // intended alarm, not a flake. Do not "fix" it by loosening the filter.
    $productSelects = array_filter(
        $queries,
        fn (array $q) => str_contains($q['query'], 'from "products"')
    );

    expect($productSelects)->toBeEmpty();
});

it('busts the product cache entry when that product is updated', function () use ($adminToken) {
    $product = Product::factory()->create(['name' => 'Original']);

    getJson("/api/v1/products/{$product->id}")->assertOk();
    expect(Cache::has("product:{$product->id}"))->toBeTrue();

    withHeader('Authorization', 'Bearer '.$adminToken())
        ->patchJson("/api/v1/products/{$product->id}", ['name' => 'Renamed'])
        ->assertOk();

    expect(Cache::has("product:{$product->id}"))->toBeFalse();

    // And the next read returns the new value, not a stale one.
    getJson("/api/v1/products/{$product->id}")
        ->assertJsonPath('data.name', 'Renamed');
});

it('busts the product cache entry when that product is deleted', function () use ($adminToken) {
    $product = Product::factory()->create();

    getJson("/api/v1/products/{$product->id}")->assertOk();

    withHeader('Authorization', 'Bearer '.$adminToken())
        ->deleteJson("/api/v1/products/{$product->id}")
        ->assertNoContent();

    expect(Cache::has("product:{$product->id}"))->toBeFalse();
});

it('leaves other products cached when one is updated', function () use ($adminToken) {
    // Invalidation must be surgical. A blunt flush would pass every test
    // above while destroying the point of caching.
    $updated = Product::factory()->create();
    $untouched = Product::factory()->create();

    getJson("/api/v1/products/{$updated->id}")->assertOk();
    getJson("/api/v1/products/{$untouched->id}")->assertOk();

    withHeader('Authorization', 'Bearer '.$adminToken())
        ->patchJson("/api/v1/products/{$updated->id}", ['name' => 'Renamed']);

    expect(Cache::has("product:{$updated->id}"))->toBeFalse();
    expect(Cache::has("product:{$untouched->id}"))->toBeTrue();
});

it('caches the category list and busts it on a category write', function () {
    Category::factory()->count(2)->create();

    expect(Cache::has('categories:all'))->toBeFalse();

    getJson('/api/v1/categories')->assertOk();

    expect(Cache::has('categories:all'))->toBeTrue();

    // No category write endpoint exists this milestone, so drive the model
    // directly — the observer is what the endpoint would trigger anyway.
    Category::factory()->create();

    expect(Cache::has('categories:all'))->toBeFalse();
});

it('busts the category list when a category is deleted, not only saved', function () {
    // CategoryObserver has two handlers and only `saved` is exercised above,
    // so a missing or no-op `deleted` handler ships unnoticed and the category
    // list serves a deleted category for up to an hour. The mutation check in
    // Step 7 covers ProductObserver only and names this path nowhere.
    $category = Category::factory()->create();

    getJson('/api/v1/categories')->assertOk();
    expect(Cache::has('categories:all'))->toBeTrue();

    $category->delete();

    expect(Cache::has('categories:all'))->toBeFalse();

    // And the refilled list no longer contains it — proving the bust actually
    // changed what a caller sees, not merely that a key vanished.
    getJson('/api/v1/categories')
        ->assertOk()
        ->assertJsonMissing(['id' => $category->id]);
});

it('busts cached products when their category is renamed', function () {
    // ProductResource nests the category's name inside the product payload,
    // so product:{id} depends on the category row as well as the product row —
    // two invalidation triggers, not the one DEC-20 claims. Without this,
    // renaming a category leaves every cached product in it serving the old
    // name for the full hour and nothing in the suite notices.
    $category = Category::factory()->create(['name' => 'Kitchen']);
    $product = Product::factory()->for($category)->create();

    getJson("/api/v1/products/{$product->id}")
        ->assertOk()
        ->assertJsonPath('data.category.name', 'Kitchen');

    $category->update(['name' => 'Cookware']);

    expect(Cache::has("product:{$product->id}"))->toBeFalse();

    // The caller sees the new name, not merely a vanished key.
    getJson("/api/v1/products/{$product->id}")
        ->assertOk()
        ->assertJsonPath('data.category.name', 'Cookware');
});

it('normalises a zero-padded product id onto the canonical cache key', function () {
    // Postgres casts '01' to 1, so /products/01 and /products/1 serve the same
    // row — but a key built from the raw route string mints two entries, and
    // ProductObserver only ever forgets "product:{$id}". Every padded variant
    // would then be an entry no write can bust, that any anonymous caller can
    // mint without limit. This is the only guard on DEC-20's bounded key count.
    $product = Product::factory()->create();
    $padded = '0'.$product->id;

    getJson("/api/v1/products/{$padded}")->assertOk();

    expect(Cache::has("product:{$product->id}"))->toBeTrue()
        ->and(Cache::has("product:{$padded}"))->toBeFalse();

    // ...and the write path can actually bust what the padded read minted.
    $product->update(['name' => 'Renamed']);

    expect(Redis::connection('cache')->keys('product:*'))->toBeEmpty();
});

it('404s on a non-numeric product id instead of a database cast error', function () {
    // `where id = 'abc'` against a bigint column raises Postgres 22P02, which
    // surfaces as a 500 on unauthenticated input to a public endpoint.
    getJson('/api/v1/products/not-a-number')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');
});
