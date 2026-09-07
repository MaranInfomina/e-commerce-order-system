<?php

namespace Tests\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A static flag is an observable side effect only because the test that
 * dispatches this also runs the consuming `queue:work --once` in the SAME
 * PHP process (Artisan::call, not a separate worker container) — see
 * QueueConnectivityTest.
 */
class QueueConnectivityProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public static bool $handled = false;

    public function handle(): void
    {
        self::$handled = true;
    }
}
