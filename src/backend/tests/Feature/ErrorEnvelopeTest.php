<?php

use Illuminate\Support\Facades\Route;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

it('returns the route-not-found envelope for an unknown endpoint', function () {
    getJson('/api/v1/does-not-exist')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'ROUTE_NOT_FOUND')
        ->assertJsonStructure(['error' => ['code', 'message']]);
});

it('returns the method-not-allowed envelope for a wrong verb', function () {
    postJson('/api/health')
        ->assertStatus(405)
        ->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED');
});

it('returns the internal-error envelope without leaking details', function () {
    Route::get('/api/v1/_explode', function () {
        throw new RuntimeException('database password is hunter2 at /var/secret.php');
    });

    $response = getJson('/api/v1/_explode');

    $response->assertStatus(500)
        ->assertJsonPath('error.code', 'INTERNAL_ERROR')
        ->assertJsonPath('error.message', 'An unexpected error occurred.');

    $body = $response->getContent();
    expect($body)->not->toContain('hunter2');
    expect($body)->not->toContain('/var/secret.php');
    expect($body)->not->toContain('RuntimeException');
    expect($body)->not->toContain('trace');
});
