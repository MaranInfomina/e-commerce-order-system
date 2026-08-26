<?php

it('lets the php-fpm worker user write every framework storage path', function (string $path) {
    $absolute = base_path($path);

    expect(is_dir($absolute))->toBeTrue("{$path} does not exist");

    $probe = escapeshellarg($absolute . '/.writable-probe');
    exec('su -s /bin/sh -c ' . escapeshellarg("touch {$probe} && rm {$probe}") . ' www-data 2>&1', $output, $status);

    expect($status)->toBe(0, "www-data cannot write {$path}: " . implode(' ', $output));
})->with([
    'storage/framework/cache/data',
    'storage/framework/sessions',
    'storage/framework/views',
    'bootstrap/cache',
]);
