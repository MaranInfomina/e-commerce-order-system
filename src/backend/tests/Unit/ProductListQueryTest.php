<?php

use App\Queries\ProductListQuery;

it('exposes exactly the six documented sort keys', function () {
    expect(array_keys(ProductListQuery::SORTS))->toBe([
        'name', '-name', 'price', '-price', 'created_at', '-created_at',
    ]);
});

it('maps each sort key to a real column and direction', function () {
    expect(ProductListQuery::SORTS['name'])->toBe(['name', 'asc']);
    expect(ProductListQuery::SORTS['-name'])->toBe(['name', 'desc']);
    expect(ProductListQuery::SORTS['price'])->toBe(['price_cents', 'asc']);
    expect(ProductListQuery::SORTS['-price'])->toBe(['price_cents', 'desc']);
    expect(ProductListQuery::SORTS['created_at'])->toBe(['created_at', 'asc']);
    expect(ProductListQuery::SORTS['-created_at'])->toBe(['created_at', 'desc']);
});
