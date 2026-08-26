<?php

use Illuminate\Support\Facades\DB;

it('runs against the dedicated test database, never the development one', function () {
    expect(DB::connection()->getDatabaseName())->toBe('coe_orders_test');
});
