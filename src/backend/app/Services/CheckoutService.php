<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    /**
     * @param  array<int, int>  $cartQuantities  productId => quantity
     */
    public function checkout(
        User $user,
        array $cartQuantities,
        string $shippingAddress,
        ?string $notes,
        ?string $idempotencyKey,
    ): Order {
        if ($cartQuantities === []) {
            throw ValidationException::withMessages(['cart' => ['Your cart is empty.']]);
        }

        if ($idempotencyKey !== null) {
            $existing = $this->findByIdempotencyKey($user->id, $idempotencyKey);

            if ($existing !== null) {
                return $existing;
            }
        }

        try {
            return DB::transaction(fn () => $this->createOrder(
                $user, $cartQuantities, $shippingAddress, $notes, $idempotencyKey,
            ));
        } catch (QueryException $e) {
            // A second, concurrent request carrying the same idempotency key
            // can race past the pre-check above and both reach the INSERT;
            // the (user_id, idempotency_key) unique constraint is the real
            // guarantee and this turns its violation into the same "return
            // the original order" behaviour instead of a raw 500.
            if ($idempotencyKey !== null && self::isUniqueViolation($e)) {
                $existing = $this->findByIdempotencyKey($user->id, $idempotencyKey);

                if ($existing !== null) {
                    return $existing;
                }
            }

            throw $e;
        }
    }

    /**
     * @param  array<int, int>  $cartQuantities
     */
    private function createOrder(
        User $user,
        array $cartQuantities,
        string $shippingAddress,
        ?string $notes,
        ?string $idempotencyKey,
    ): Order {
        // lockForUpdate serializes two concurrent checkouts against the same
        // product row: the second transaction blocks until the first commits
        // or rolls back, then re-reads current stock_quantity. Product's
        // SoftDeletes global scope already excludes a deleted product here,
        // so a phantom cart line resolves to "missing" below.
        $products = Product::query()
            ->whereIn('id', array_keys($cartQuantities))
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $totalCents = 0;
        $lines = [];

        foreach ($cartQuantities as $productId => $quantity) {
            $product = $products->get($productId);

            if ($product === null) {
                throw ValidationException::withMessages([
                    'cart' => ['One or more items in your cart are no longer available.'],
                ]);
            }

            if ($product->stock_quantity < $quantity) {
                throw ValidationException::withMessages([
                    'stock' => ["Not enough stock for {$product->name}."],
                ]);
            }

            $lineTotal = $product->price_cents * $quantity;
            $totalCents += $lineTotal;

            $lines[] = ['product' => $product, 'quantity' => $quantity, 'line_total_cents' => $lineTotal];
        }

        foreach ($lines as $line) {
            // The products_stock_quantity_non_negative CHECK (Milestone 1)
            // is the backstop if the stock check above were ever bypassed;
            // this decrement is what it backs up.
            $line['product']->decrement('stock_quantity', $line['quantity']);
        }

        $order = Order::create([
            'user_id' => $user->id,
            'status' => Order::STATUS_PENDING,
            'idempotency_key' => $idempotencyKey,
            'shipping_address' => $shippingAddress,
            'notes' => $notes,
            'total_cents' => $totalCents,
        ]);

        foreach ($lines as $line) {
            $order->items()->create([
                'product_id' => $line['product']->id,
                'product_name' => $line['product']->name,
                'product_sku' => $line['product']->sku,
                'product_image_path' => $line['product']->image_path,
                'unit_price_cents' => $line['product']->price_cents,
                'quantity' => $line['quantity'],
                'line_total_cents' => $line['line_total_cents'],
            ]);
        }

        $order->statusHistory()->create([
            'status' => Order::STATUS_PENDING,
            'caused_by' => 'system',
        ]);

        return $order;
    }

    private function findByIdempotencyKey(int $userId, string $key): ?Order
    {
        return Order::query()
            ->where('user_id', $userId)
            ->where('idempotency_key', $key)
            ->first();
    }

    private static function isUniqueViolation(QueryException $e): bool
    {
        // Postgres SQLSTATE 23505 = unique_violation.
        return $e->getCode() === '23505';
    }
}
