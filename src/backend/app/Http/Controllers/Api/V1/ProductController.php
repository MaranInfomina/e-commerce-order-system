<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductIndexRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Queries\ProductListQuery;
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

        return ProductResource::collection($products);
    }
}
