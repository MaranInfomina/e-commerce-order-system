<?php

use function Pest\Laravel\getJson;

it('reports service and database health', function () {
    getJson('/api/health')
        ->assertOk()
        ->assertExactJson([
            'status' => 'ok',
            'database' => 'ok',
        ]);
});
