<?php

use App\Models\Category;
use App\Models\Product;

use function Pest\Laravel\getJson;

it('paginates with fifteen items per page by default', function () {
    Product::factory()->count(20)->create();

    getJson('/api/v1/products')
        ->assertOk()
        ->assertJsonCount(15, 'data')
        ->assertJsonPath('meta.total', 20)
        ->assertJsonPath('meta.per_page', 15)
        ->assertJsonPath('meta.current_page', 1);
});

it('honours a per_page within range and rejects one above the maximum', function () {
    Product::factory()->count(60)->create();

    getJson('/api/v1/products?per_page=50')
        ->assertOk()
        ->assertJsonCount(50, 'data');

    getJson('/api/v1/products?per_page=101')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => ['per_page']]]);
});

it('searches name and description case-insensitively, matching only the intended products', function () {
    Product::factory()->create(['name' => 'Titanium Kettle', 'description' => 'boils water']);
    Product::factory()->create(['name' => 'Copper Pan', 'description' => 'holds TITANIUM coating']);
    Product::factory()->create(['name' => 'Wooden Spoon', 'description' => 'stirs things']);

    // sort=name makes the row order deterministic so we can assert exactly
    // which two products matched, not merely how many.
    $response = getJson('/api/v1/products?search=titanium&sort=name')->assertOk();

    expect($response->json('meta.total'))->toBe(2);
    expect($response->json('data.*.name'))->toBe(['Copper Pan', 'Titanium Kettle']);
});

it('escapes literal LIKE metacharacters in the search term instead of treating them as wildcards', function () {
    Product::factory()->create(['name' => 'Cable 50% Off', 'description' => 'a discounted cable']);
    Product::factory()->create(['name' => 'Regular Cable', 'description' => 'a full price cable']);

    // A literal '%' in the search term must match only the row whose text
    // actually contains '%' — not act as a SQL wildcard matching every row.
    getJson('/api/v1/products?search='.urlencode('%'))
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.name', 'Cable 50% Off');

    // A literal '_' must match nothing here — not act as a single-character
    // wildcard matching every row.
    getJson('/api/v1/products?search='.urlencode('_'))
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
});

it('filters by category slug and by active status', function () {
    $wanted = Category::factory()->create(['slug' => 'kitchen']);
    $other = Category::factory()->create(['slug' => 'garden']);

    Product::factory()->count(3)->for($wanted)->create();
    Product::factory()->count(4)->for($other)->create();
    Product::factory()->count(2)->inactive()->for($wanted)->create();

    getJson('/api/v1/products?category=kitchen')
        ->assertOk()
        ->assertJsonPath('meta.total', 5);

    getJson('/api/v1/products?category=kitchen&is_active=1')
        ->assertOk()
        ->assertJsonPath('meta.total', 3);
});

it('applies inclusive price bounds that combine', function () {
    Product::factory()->create(['price_cents' => 999]);
    Product::factory()->create(['price_cents' => 1000]);
    Product::factory()->create(['price_cents' => 5000]);
    Product::factory()->create(['price_cents' => 5001]);

    getJson('/api/v1/products?min_price=1000')->assertJsonPath('meta.total', 3);
    getJson('/api/v1/products?max_price=5000')->assertJsonPath('meta.total', 3);
    getJson('/api/v1/products?min_price=1000&max_price=5000')->assertJsonPath('meta.total', 2);
});

it('rejects a min_price greater than max_price instead of silently returning no results', function () {
    // The gte:min_price rule on max_price is only conditionally applied
    // (Rule::when($this->filled('min_price'), ...)) so that max_price alone
    // is not wrongly rejected — see ProductIndexRequest. This test proves
    // that conditional wrapper still enforces the ordering it exists for
    // when both bounds are present, which is the property most likely to
    // be disabled by accident in a future edit to that condition.
    getJson('/api/v1/products?min_price=5000&max_price=1000')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => ['max_price']]]);
});

it('sorts by each allowed key in both directions', function () {
    Product::factory()->create(['name' => 'Alpha', 'price_cents' => 300]);
    Product::factory()->create(['name' => 'Beta', 'price_cents' => 100]);
    Product::factory()->create(['name' => 'Gamma', 'price_cents' => 200]);

    expect(getJson('/api/v1/products?sort=name')->json('data.*.name'))
        ->toBe(['Alpha', 'Beta', 'Gamma']);

    expect(getJson('/api/v1/products?sort=-name')->json('data.*.name'))
        ->toBe(['Gamma', 'Beta', 'Alpha']);

    expect(getJson('/api/v1/products?sort=price')->json('data.*.price_cents'))
        ->toBe([100, 200, 300]);

    expect(getJson('/api/v1/products?sort=-price')->json('data.*.price_cents'))
        ->toBe([300, 200, 100]);
});

it('rejects an unknown sort value instead of ignoring it', function () {
    Product::factory()->count(3)->create();

    getJson('/api/v1/products?sort=price_cents')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => ['sort']]]);
});

it('includes the category on each product', function () {
    $category = Category::factory()->create(['name' => 'Kitchen', 'slug' => 'kitchen']);
    Product::factory()->for($category)->create();

    getJson('/api/v1/products')
        ->assertOk()
        ->assertJsonPath('data.0.category.slug', 'kitchen')
        ->assertJsonPath('data.0.category.name', 'Kitchen');
});
