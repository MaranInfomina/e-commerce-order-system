<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductIndexRequest;
use App\Http\Requests\ProductStoreRequest;
use App\Http\Requests\ProductUpdateRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Queries\ProductListQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use OpenApi\Attributes as OA;

class ProductController extends Controller
{
    #[OA\Get(
        path: '/api/v1/products',
        summary: 'List products',
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 255)),
            new OA\Parameter(name: 'category', in: 'query', description: 'Category slug.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'min_price', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 0)),
            new OA\Parameter(name: 'max_price', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 0)),
            new OA\Parameter(name: 'is_active', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated products',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Product')),
                    new OA\Property(property: 'links', type: 'object'),
                    new OA\Property(property: 'meta', type: 'object'),
                ]),
            ),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ],
    )]
    public function index(
        ProductIndexRequest $request,
        ProductListQuery $listQuery,
    ): AnonymousResourceCollection {
        $filters = $request->validated();

        // Eager load the category to avoid one query per row.
        $query = Product::query()->with('category');

        $products = $listQuery->apply($query, $filters)
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        // Request::path() is host-free even during SSR, when the request
        // itself arrives as http://nginx/api/v1/products — without this the
        // paginator defaults to $request->url() and serializes the internal
        // container hostname into links.* and meta.path/meta.links on every
        // server-rendered page (I3).
        $products->setPath('/'.$request->path());

        return ProductResource::collection($products);
    }

    #[OA\Get(
        path: '/api/v1/products/{product}',
        summary: 'Get a single product',
        parameters: [
            new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The product', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Product')])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ],
    )]
    public function show(string $product): ProductResource
    {
        // The parameter is the raw route string, NOT an implicitly bound
        // Product. That is the whole point: with `Product $product` Laravel
        // resolves the model — issuing `select * from "products" where id = ?`
        // — BEFORE this method body runs, so the products SELECT happens on
        // every request and the cache saves only the category eager-load.
        // A "cache hit" that still hits the database is not a cache hit, and
        // the test below could never pass while the binding was in place.
        //
        // Because it is raw text, it MUST be normalised before it becomes a
        // key. Postgres casts '01' to 1, so /products/01 and /products/1 serve
        // the same row (verified: both return 200) — but they would mint two
        // different keys, and ProductObserver only ever forgets
        // "product:{$product->id}", the canonical spelling. Every padded
        // variant would be an entry no write can bust, that any anonymous
        // caller can mint without limit. Cast to int so the key is always
        // canonical.
        //
        // Non-numeric input is rejected here rather than handed to
        // findOrFail: that would send `where id = 'abc'` to a bigint column,
        // and Postgres raises 22P02 — a 500 on unauthenticated input to a
        // public endpoint. Throwing the exception findOrFail would have
        // thrown gets the identical NOT_FOUND envelope out of
        // ApiExceptionRenderer.
        if (! ctype_digit($product)) {
            throw (new ModelNotFoundException)->setModel(Product::class);
        }

        $key = 'product:'.(int) $product;

        // Cache the resolved model, not the rendered response: the resource
        // may serialize differently per request, and a cached response body
        // would freeze whatever the first caller happened to ask for.
        $cached = Cache::get($key);

        if ($cached === null) {
            // findOrFail throws the same ModelNotFoundException that implicit
            // binding threw, so the 404 envelope is byte-for-byte unchanged —
            // and because it throws before the Cache::put below, a 404 is
            // never cached.
            $cached = Product::with('category')->findOrFail((int) $product);

            Cache::put($key, $cached, 3600);
        }

        return ProductResource::make($cached);
    }

    #[OA\Post(
        path: '/api/v1/products',
        summary: 'Create a product (admin only)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(ref: '#/components/schemas/ProductStoreRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Product created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Product')])),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 403, description: 'Not an admin', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ],
    )]
    public function store(ProductStoreRequest $request): JsonResponse
    {
        $this->authorize('create', Product::class);

        $product = Product::create($request->validated());

        return ProductResource::make($product->load('category'))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Patch(
        path: '/api/v1/products/{product}',
        summary: 'Update a product (admin only)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(ref: '#/components/schemas/ProductUpdateRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Product updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Product')])),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 403, description: 'Not an admin', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ],
    )]
    public function update(ProductUpdateRequest $request, Product $product): ProductResource
    {
        $this->authorize('update', $product);

        $product->update($request->validated());

        return ProductResource::make($product->load('category'));
    }

    #[OA\Delete(
        path: '/api/v1/products/{product}',
        summary: 'Delete a product (admin only)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Product deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 403, description: 'Not an admin', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ],
    )]
    public function destroy(Product $product): JsonResponse
    {
        $this->authorize('delete', $product);

        $product->delete();

        return response()->json(null, 204);
    }
}
