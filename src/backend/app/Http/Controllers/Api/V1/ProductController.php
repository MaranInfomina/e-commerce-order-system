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

    public function show(Product $product): ProductResource
    {
        return ProductResource::make($product->load('category'));
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
