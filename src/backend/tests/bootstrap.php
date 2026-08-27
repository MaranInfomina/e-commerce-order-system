<?php

require __DIR__.'/../vendor/autoload.php';

// docker-compose.yml injects DB_TEST_DATABASE into the php-fpm container,
// derived from POSTGRES_TEST_DB (root .env override, defaulting to
// "coe_orders_test" if unset — spec CR-3, DEC-6). That is the single source
// of truth for the test database name; nothing else should hardcode it.
//
// Capture the real development database name *before* it is overwritten
// below, so TestDatabaseGuardTest can prove the suite is not pointed at it.
define('DEV_DATABASE_NAME', getenv('DB_DATABASE') ?: 'coe_orders');

function resolve_test_database_name(): string
{
    return getenv('DB_TEST_DATABASE') ?: 'coe_orders_test';
}

// PHPUnit applies phpunit.xml's <php> block (including any force="true"
// DB_DATABASE entry) before running this bootstrap script — confirmed by
// reading PHPUnit\TextUI\Application::run() (PhpHandler runs, then
// BootstrapLoader) and by a live experiment with a throwaway config.
// Setting DB_DATABASE here therefore has the last word: it overrides
// whatever the <php> block set, so the container's DB_TEST_DATABASE stays
// the single source of truth instead of a literal duplicated in
// phpunit.xml.
$testDatabase = resolve_test_database_name();

putenv("DB_DATABASE={$testDatabase}");
$_ENV['DB_DATABASE'] = $testDatabase;
$_SERVER['DB_DATABASE'] = $testDatabase;
