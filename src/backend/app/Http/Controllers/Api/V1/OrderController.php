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
            Bus::chain([
                new ProcessPayment($order->id),
                new SendOrderConfirmation($order->id),
            ])->onQueue('orders')->dispatch();

            $this->carts->clear($user->id);
        }

        return OrderResource::make($order->load(['items', 'statusHistory']))
            ->response()
            ->setStatusCode($order->wasRecentlyCreated ? 201 : 200);
    }
}
