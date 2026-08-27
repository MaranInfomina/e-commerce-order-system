<?php

use App\Models\Category;

use function Pest\Laravel\getJson;

it('lists every category ordered by name', function () {
    Category::factory()->create(['name' => 'Kitchen', 'slug' => 'kitchen']);
    Category::factory()->create(['name' => 'Bathroom', 'slug' => 'bathroom']);
    Category::factory()->create(['name' => 'Garden', 'slug' => 'garden']);

    $response = getJson('/api/v1/categories')->assertOk();

    expect($response->json('data.*.name'))->toBe(['Bathroom', 'Garden', 'Kitchen']);
    expect($response->json('data.0'))->toHaveKeys(['id', 'name', 'slug']);
});

it('returns an empty data array when there are no categories', function () {
    getJson('/api/v1/categories')
        ->assertOk()
        ->assertExactJson(['data' => []]);
});
