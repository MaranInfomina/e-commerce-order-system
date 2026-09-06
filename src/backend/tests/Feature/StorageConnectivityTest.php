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
// Scoped to the ONE test that needs credentials, not the file. As a
// beforeEach it also skipped the pure config assertions below — which need no
// credentials at all, and which are most likely to be wrong on exactly the
// clean clone where the skip fires.
$requiresCredentials = function (): void {
    if (config('filesystems.disks.s3.key') === '' || config('filesystems.disks.s3.key') === null) {
        test()->markTestSkipped('Garage credentials are not configured; run ./setup.sh to provision them.');
    }
};

it('round-trips an object through garage', function () use ($requiresCredentials) {
    $requiresCredentials();

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

it('publishes image urls at a client-reachable host, not the compose-internal endpoint', function () {
    // The single most user-visible failure this task can ship: every product
    // image a dead link. Storage::disk('s3')->url() returns {AWS_URL}/{key},
    // and with AWS_URL unset Flysystem falls back to
    // {AWS_ENDPOINT}/{bucket}/{key} — publishing
    // http://garage:3900/coe-products/... , a name only a container on the
    // compose network resolves, behind an S3 API that demands a SigV4
    // signature no browser sends.
    //
    // Every other assertion in the suite checks image_url with
    // `is_string($url) && $url !== ''`, which that broken string satisfies.
    // This is a config assertion, not a reachability one — no in-process test
    // can prove a browser opens the URL, and Step 15 still owns that — but it
    // catches both realistic regressions: the variable going missing, and
    // someone pointing it at the internal endpoint. The invariant holds in
    // both environments, because Garage's SigV4 S3 API and its anonymous web
    // endpoint are different services on different addresses.
    $url = config('filesystems.disks.s3.url');

    expect($url)->toBeString()->not->toBe('');
    expect(parse_url($url, PHP_URL_SCHEME))->toBeIn(['http', 'https']);

    $endpointHost = parse_url((string) config('filesystems.disks.s3.endpoint'), PHP_URL_HOST);

    expect(parse_url($url, PHP_URL_HOST))->not->toBe($endpointHost);
});
