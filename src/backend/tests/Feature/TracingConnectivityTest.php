<?php

use Illuminate\Support\Facades\Http;

it('exports a real span reaching jaeger', function () {
    $jaegerUp = rescue(fn () => Http::timeout(2)->get('http://jaeger:16686/api/services'), null, false);

    if ($jaegerUp === null || ! $jaegerUp->successful()) {
        test()->markTestSkipped('Jaeger is not reachable.');
    }

    $this->getJson('/api/health');

    sleep(2);

    $services = Http::get('http://jaeger:16686/api/services')->json('data');

    expect($services)->toContain('coe-backend');
});
