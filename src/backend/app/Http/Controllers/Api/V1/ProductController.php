<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductIndexRequest;
use App\Http\Requests\ProductStoreRequest;
use App\Http\Requests\ProductUpdateRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Queries\ProductListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;

class ProductController extends Controller
{
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
        // canonical; non-numeric input never reaches the cache and falls
        // straight through to findOrFail, which 404s exactly as before.
        if (! ctype_digit($product)) {
            return ProductResource::make(
                Product::with('category')->findOrFail($product)
            );
        }

        $key = 'product:'.(int) $product;

        // Cache the resolved model, not the rendered response: the resource
        // may serialize differently per request, and a cached response body
        // would freeze whatever the first caller happened to ask for.
        $cached = Cache::get($key);

        if ($cached === null) {
            // findOrFail throws the same ModelNotFoundException that implicit
            // binding threw, so the 404 envelope is byte-for-byte unchanged.
            // Note this is deliberately not Cache::remember: remember() would
            // store the null on a miss and cache 404s for an hour.
            $cached = Product::with('category')->findOrFail((int) $product);

            Cache::put($key, $cached, 3600);
        }

        return ProductResource::make($cached);
    }

    public function store(ProductStoreRequest $request): JsonResponse
    {
        $this->authorize('create', Product::class);

        $product = Product::create($request->validated());

        return ProductResource::make($product->load('category'))
            ->response()
            ->setStatusCode(201);
    }

    public function update(ProductUpdateRequest $request, Product $product): ProductResource
    {
        $this->authorize('update', $product);

        $product->update($request->validated());

        return ProductResource::make($product->load('category'));
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->authorize('delete', $product);

        $product->delete();

        return response()->json(null, 204);
    }
}
