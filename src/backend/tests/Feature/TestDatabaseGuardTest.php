<?php

use Illuminate\Support\Facades\DB;

it('runs against the dedicated test database, never the development one', function () {
    $resolved = DB::connection()->getDatabaseName();

    // resolve_test_database_name() and DEV_DATABASE_NAME come from
    // tests/bootstrap.php, the single place that derives the test database
    // name from the container's DB_TEST_DATABASE variable — this test must
    // not carry its own independent copy of either name.
    expect($resolved)->toBe(resolve_test_database_name());

    expect($resolved)->not->toBe(DEV_DATABASE_NAME);
});
