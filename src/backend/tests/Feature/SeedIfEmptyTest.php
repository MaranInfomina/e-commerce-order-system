<?php

use App\Models\Product;

use function Pest\Laravel\artisan;

it('seeds when the catalog is empty', function () {
    expect(Product::count())->toBe(0);

    artisan('app:seed-if-empty')->assertSuccessful();

    expect(Product::count())->toBeGreaterThan(0);
});

it('does not seed again when the catalog already has products', function () {
    artisan('app:seed-if-empty')->assertSuccessful();
    $afterFirstRun = Product::count();

    artisan('app:seed-if-empty')->assertSuccessful();

    expect(Product::count())->toBe($afterFirstRun);
});

it('treats a soft-deleted-only catalog as populated', function () {
    $product = Product::factory()->create();
    $product->delete();

    artisan('app:seed-if-empty')->assertSuccessful();

    // One soft-deleted product means the seeder has already run; re-seeding
    // would repopulate a catalog an operator deliberately emptied.
    expect(Product::withTrashed()->count())->toBe(1);
});
