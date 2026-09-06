<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductImageRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductImageController extends Controller
{
    public function store(ProductImageRequest $request, Product $product): ProductResource
    {
        $this->authorize('update', $product);

        $previous = $product->image_path;

        // A UUID in the key means replacing an image needs no cache bust at
        // the CDN or browser — the URL simply changes.
        $extension = $request->file('image')->extension();
        $key = "products/{$product->id}/".Str::uuid().".{$extension}";

        Storage::disk('s3')->put($key, $request->file('image')->get());

        // $product->update(), never Product::whereKey($id)->update(): the
        // query builder fires no model event, so ProductObserver never runs
        // and every cached product keeps serving image_url: null for the full
        // 3600s TTL behind a green suite.
        $product->update(['image_path' => $key]);

        // Delete the old object only after the new key is committed, so a
        // failure mid-way leaves the product with a working image rather
        // than none.
        if ($previous !== null && $previous !== $key) {
            Storage::disk('s3')->delete($previous);
        }

        return ProductResource::make($product->fresh()->load('category'));
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        if ($product->image_path !== null) {
            Storage::disk('s3')->delete($product->image_path);
            $product->update(['image_path' => null]);
        }

        return response()->json(null, 204);
    }
}
