<?php

use Illuminate\Support\Facades\Log;
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

    // The 500 path genuinely logs in production — that behaviour is correct
    // and untouched. This test deliberately triggers an exception, so
    // without a spy the real handler writes ~60 lines (including the secret
    // string this test exists to prove never reaches the response) to
    // stderr on every run. Log::spy() intercepts the write for this test
    // only; the envelope assertions below still prove nothing leaks into
    // the HTTP response, which is the actual contract under test.
    Log::spy();

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
