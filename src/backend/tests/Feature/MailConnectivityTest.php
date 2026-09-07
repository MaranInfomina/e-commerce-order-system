<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

// Mirrors the reachability-skip shape used elsewhere in this suite for real
// external dependencies (e.g. tests/Feature/StorageConnectivityTest.php,
// tests/Feature/QueueConnectivityTest.php): skip when the real dependency is
// not reachable, never silently pass when it IS reachable but broken.
$requiresMailpit = function (): void {
    $response = rescue(fn () => Http::timeout(2)->get(mailpitApiBase().'/api/v1/info'), null, false);

    if ($response === null || ! $response->successful()) {
        test()->markTestSkipped('Mailpit is not reachable at '.mailpitApiBase());
    }
};

function mailpitApiBase(): string
{
    // The web UI/API always listens on 8025 regardless of MAIL_PORT (SMTP).
    $host = parse_url(config('mail.mailers.smtp.host') ? 'tcp://'.config('mail.mailers.smtp.host') : 'tcp://mailpit', PHP_URL_HOST) ?: 'mailpit';

    return "http://{$host}:8025";
}

it('delivers a real email visible through mailpit\'s http api', function () use ($requiresMailpit) {
    $requiresMailpit();

    // phpunit.xml forces MAIL_MAILER=array with force="true", which
    // unconditionally wins over any shell-level `env MAIL_MAILER=smtp` —
    // PHPUnit applies its forced env values after the process already has
    // its environment, so a shell override can never reach it. Overriding
    // Laravel's runtime config directly is the only way this one test can
    // genuinely send through Mailpit while the rest of the suite stays
    // hermetic on the array mailer.
    config(['mail.default' => 'smtp']);

    $subject = 'COE connectivity probe '.Str::random(12);

    Mail::raw('connectivity probe body', function ($message) use ($subject) {
        $message->to('probe@example.com')->subject($subject);
    });

    $search = Http::get(mailpitApiBase().'/api/v1/search', ['query' => "subject:\"{$subject}\""]);

    expect($search->successful())->toBeTrue();
    expect($search->json('total'))->toBeGreaterThanOrEqual(1);
});
