<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderStoreRequest;
use App\Http\Resources\OrderResource;
use App\Jobs\ProcessPayment;
use App\Jobs\SendOrderConfirmation;
use App\Repositories\CartRepository;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrderController extends Controller
{
    public function __construct(
        private readonly CartRepository $carts,
        private readonly CheckoutService $checkout,
    ) {}

    public function store(OrderStoreRequest $request): JsonResponse
    {
        $user = $request->user();

        $idempotencyKey = $request->header('Idempotency-Key');
        $idempotencyKey = ($idempotencyKey === null || $idempotencyKey === '') ? null : $idempotencyKey;

        if ($idempotencyKey !== null && mb_strlen($idempotencyKey) > 255) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['The Idempotency-Key header must not exceed 255 characters.'],
            ]);
        }

        $order = $this->checkout->checkout(
            user: $user,
            cartQuantities: $this->carts->get($user->id),
            shippingAddress: $request->validated('shipping_address'),
            notes: $request->validated('notes'),
            idempotencyKey: $idempotencyKey,
        );

        // wasRecentlyCreated is Eloquent's own flag, true only for the
        // instance that just ran through Model::create() in this request —
        // an idempotent replay returns an instance fetched via a plain
        // where()->first() query, which is never "recently created". Post-
        // commit side effects only happen for a genuinely new order:
        // dispatching a second job chain or clearing an already-empty cart on
        // a replay would be a silent duplicate side effect.
        if ($order->wasRecentlyCreated) {
            try {
                Bus::chain([
                    new ProcessPayment($order->id),
                    new SendOrderConfirmation($order->id),
                ])->onQueue('orders')->dispatch();
            } catch (Throwable $e) {
                // Under QUEUE_CONNECTION=sync (forced by phpunit.xml under the
                // test suite), dispatch() runs the chain's first job INLINE,
                // in this request. If that job exhausts its retries,
                // Illuminate\Queue\SyncQueue::handleException() calls
                // $job->fail($e) — which has already written failed_jobs and,
                // via ProcessPayment::failed(), already transitioned this
                // order to payment_failed — and then rethrows purely so a
                // `queue:work` process would see the failure too. Under the
                // real rabbitmq connection this call only enqueues and
                // returns immediately, so it never throws here: this catch
                // exists solely so a job's own (already-handled) failure
                // does not also fail the checkout response that triggered it.
                report($e);
            }

            $this->carts->clear($user->id);

            // Under sync the chain above just ran inline and may have
            // changed this order's status out from under this in-memory
            // instance — OrderStatusTransitioner writes through a raw
            // DB::table() update, and every job re-fetches its OWN Order by
            // id rather than sharing this object. Refresh so the response
            // reflects what actually happened instead of the pending
            // snapshot taken before dispatch. Under the real queue this is a
            // harmless no-op re-fetch of the same still-pending row.
            $order->refresh();
        }

        return OrderResource::make($order->load(['items', 'statusHistory']))
            ->response()
            ->setStatusCode($order->wasRecentlyCreated ? 201 : 200);
    }
}
