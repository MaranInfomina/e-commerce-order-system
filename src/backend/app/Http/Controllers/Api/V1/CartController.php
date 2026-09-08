<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CartItemRequest;
use App\Http\Resources\CartResource;
use App\Models\Product;
use App\Repositories\CartRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class CartController extends Controller
{
    public function __construct(private readonly CartRepository $carts) {}

    #[OA\Get(
        path: '/api/v1/cart',
        summary: "Get the caller's cart",
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'The cart', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Cart')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ],
    )]
    public function show(Request $request): JsonResponse
    {
        return CartResource::make($this->resolve($request->user()->id))->response();
    }

    #[OA\Post(
        path: '/api/v1/cart/items',
        summary: 'Add an item to the cart (increments an existing line)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(ref: '#/components/schemas/CartItemRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Cart after the add', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Cart')])),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ],
    )]
    public function addItem(CartItemRequest $request): JsonResponse
    {
        $data = $request->validated();
        $userId = $request->user()->id;
        $productId = (int) $data['product_id'];

        $newQuantity = $this->carts->increment(
            $userId,
            $productId,
            (int) $data['quantity'],
        );

        // HINCRBY bounds the delta, not the result: repeated adds of the
        // maximum walk the field up without limit, so the request rule alone
        // would let two POSTs of 1000 leave the line at 2000. Clamping here is
        // also what gives increment()'s return value a purpose.
        if ($newQuantity > CartItemRequest::MAX_QUANTITY) {
            $this->carts->setQuantity($userId, $productId, CartItemRequest::MAX_QUANTITY);
        }

        return CartResource::make($this->resolve($userId))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Patch(
        path: '/api/v1/cart/items/{product}',
        summary: 'Set an exact quantity for a cart line',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(ref: '#/components/schemas/CartItemRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Cart after the update', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Cart')])),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ],
    )]
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

    #[OA\Delete(
        path: '/api/v1/cart/items/{product}',
        summary: 'Remove a line from the cart',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Item removed'),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ],
    )]
    public function removeItem(Request $request, Product $product): JsonResponse
    {
        $this->carts->remove($request->user()->id, $product->id);

        return response()->json(null, 204);
    }

    #[OA\Delete(
        path: '/api/v1/cart',
        summary: 'Empty the cart',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 204, description: 'Cart cleared'),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ],
    )]
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
