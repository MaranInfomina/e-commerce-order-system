<?php

it('lets the php-fpm worker user write every framework storage path', function (string $path) {
    // This only proves anything inside the real php-fpm container, where
    // `docker compose exec` runs as root (no USER directive in the
    // Dockerfile) and root can `su` to any account with no password. CI runs
    // this suite directly on the bare GitHub Actions runner via
    // shivammathur/setup-php, as the non-root `runner` user — and a non-root
    // caller can never `su` to another account without that account's
    // password, by design, regardless of how the target's own permissions
    // are set up. That is a real Unix rule, not something `sudo` papers over
    // for free: `sudo` isn't installed in this Alpine image at all, so
    // swapping to it here would only work in the one environment (the bare
    // runner) where this check no longer verifies anything real about the
    // Docker image's file ownership.
    if (function_exists('posix_getuid') && posix_getuid() !== 0) {
        test()->markTestSkipped('Not running as root - su cannot prove anything about a non-root caller.');
    }

    $absolute = base_path($path);

    expect(is_dir($absolute))->toBeTrue("{$path} does not exist");

    $probe = escapeshellarg($absolute.'/.writable-probe');
    exec('su -s /bin/sh -c '.escapeshellarg("touch {$probe} && rm {$probe}").' www-data 2>&1', $output, $status);

    expect($status)->toBe(0, "www-data cannot write {$path}: ".implode(' ', $output));
})->with([
    'storage/framework/cache/data',
    'storage/framework/sessions',
    'storage/framework/views',
    'bootstrap/cache',
]);
