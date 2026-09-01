<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CartItemRequest;
use App\Http\Resources\CartResource;
use App\Models\Product;
use App\Repositories\CartRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(private readonly CartRepository $carts) {}

    public function show(Request $request): JsonResponse
    {
        return CartResource::make($this->resolve($request->user()->id))->response();
    }

    public function addItem(CartItemRequest $request): JsonResponse
    {
        $data = $request->validated();

        $this->carts->increment(
            $request->user()->id,
            (int) $data['product_id'],
            (int) $data['quantity'],
        );

        return CartResource::make($this->resolve($request->user()->id))
            ->response()
            ->setStatusCode(201);
    }

    public function updateItem(CartItemRequest $request, Product $product): JsonResponse
    {
        // setQuantity, not increment: PATCH sets an exact quantity.
        $this->carts->setQuantity(
            $request->user()->id,
            $product->id,
            (int) $request->validated()['quantity'],
        );

        return CartResource::make($this->resolve($request->user()->id))->response();
    }

    public function removeItem(Request $request, Product $product): JsonResponse
    {
        $this->carts->remove($request->user()->id, $product->id);

        return response()->json(null, 204);
    }

    public function clear(Request $request): JsonResponse
    {
        $this->carts->clear($request->user()->id);

        return response()->json(null, 204);
    }

    /**
     * Turn the stored [productId => quantity] map into live products.
     *
     * A line whose product has since been soft-deleted is removed from the
     * hash rather than merely hidden, so a cart cannot accumulate phantom
     * entries that reappear if the product is restored.
     *
     * @return array{items: array<int, array{product: Product, quantity: int}>}
     */
    private function resolve(int $userId): array
    {
        $quantities = $this->carts->get($userId);

        if ($quantities === []) {
            return ['items' => []];
        }

        $products = Product::query()
            ->with('category')
            ->whereIn('id', array_keys($quantities))
            ->get()
            ->keyBy('id');

        $items = [];

        foreach ($quantities as $productId => $quantity) {
            $product = $products->get($productId);

            if ($product === null) {
                $this->carts->remove($userId, $productId);

                continue;
            }

            $items[] = ['product' => $product, 'quantity' => $quantity];
        }

        return ['items' => $items];
    }
}
