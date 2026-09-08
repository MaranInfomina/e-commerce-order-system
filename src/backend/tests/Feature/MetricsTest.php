<?php

use function Pest\Laravel\getJson;

it('exposes prometheus-format metrics after real requests have happened', function () {
    getJson('/api/health');

    $response = getJson('/api/metrics');

    $response->assertOk();
    expect($response->getContent())->toContain('coe_http_requests_total');
});
