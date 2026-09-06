<?php

use Illuminate\Support\Facades\Storage;

// This file deliberately does NOT use Storage::fake(). Its whole purpose is
// to prove the real Garage round-trip works before anything depends on it.
// The product image tests that follow may fake the disk; this one may not.

// ...which means it can only run where real credentials exist. A clean clone
// has no .env, so AWS_ACCESS_KEY_ID is empty and Garage has no bucket, key or
// layout until ./setup.sh creates them — and Task 11's clean-clone gate runs
// this suite with a bare `docker compose up`. Skipping on absent credentials
// keeps that gate honest: the round-trip is still PROVEN wherever the stack is
// actually configured, and merely SKIPPED where it provably cannot work.
// Never widen this to catch a connection failure — a configured stack that
// cannot reach Garage must fail loudly, not skip quietly.
beforeEach(function () {
    if (config('filesystems.disks.s3.key') === '' || config('filesystems.disks.s3.key') === null) {
        $this->markTestSkipped('Garage credentials are not configured; run ./setup.sh to provision them.');
    }
});

it('round-trips an object through garage', function () {
    $key = 'connectivity/'.uniqid().'.txt';

    Storage::disk('s3')->put($key, 'hello garage');

    expect(Storage::disk('s3')->exists($key))->toBeTrue();
    expect(Storage::disk('s3')->get($key))->toBe('hello garage');

    Storage::disk('s3')->delete($key);

    expect(Storage::disk('s3')->exists($key))->toBeFalse();
});

it('uses path-style addressing', function () {
    // Virtual-host style would try to resolve coe-products.garage:3900.
    expect(config('filesystems.disks.s3.use_path_style_endpoint'))->toBeTrue();
});
