<?php

namespace App\Jobs;

use App\Mail\OrderConfirmationMail;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendOrderConfirmation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(public readonly int $orderId) {}

    public function handle(): void
    {
        $order = Order::with(['user', 'items'])->findOrFail($this->orderId);

        Mail::to($order->user->email)->send(new OrderConfirmationMail($order));

        // The sync driver (phpunit.xml forces QUEUE_CONNECTION=sync under the
        // whole suite) has no concept of delay: Illuminate\Queue\SyncQueue::later()
        // just calls push() and runs the job immediately, in this same request.
        // Left unguarded, that would advance a just-paid order straight to
        // shipped before the checkout response is even built, which is not
        // "30 seconds later" under any real broker — it is an artifact of a
        // driver that cannot honour delay() at all. Skipping the dispatch
        // when the default connection is sync keeps this job's actual delayed
        // scheduling semantics identical between environments instead of
        // silently collapsing to zero delay whenever sync is in effect.
        if (config('queue.default') !== 'sync') {
            AdvanceOrderToShipped::dispatch($order->id)
                ->onQueue('orders')
                ->delay(now()->addSeconds(30));
        }
    }
}
