<?php

use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;

it('reports service and database health', function () {
    getJson('/api/health')
        ->assertOk()
        ->assertExactJson([
            'status' => 'ok',
            'database' => 'ok',
        ]);
});

it('reports a degraded status with 503 when the database is unreachable, without disclosing why', function () {
    // Point the pgsql connection at a port nothing listens on so getPdo()
    // fails fast with a connection refusal instead of a slow timeout, and
    // purge so the next connection attempt actually uses the new config.
    $originalPort = config('database.connections.pgsql.port');
    config(['database.connections.pgsql.port' => 1]);
    DB::purge('pgsql');

    try {
        getJson('/api/health')
            ->assertStatus(503)
            // assertExactJson also asserts no other keys are present — in
            // particular, no exception message or reason leaks alongside
            // "unreachable".
            ->assertExactJson([
                'status' => 'degraded',
                'database' => 'unreachable',
            ]);
    } finally {
        config(['database.connections.pgsql.port' => $originalPort]);
        DB::purge('pgsql');
    }
});
