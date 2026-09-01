<?php

namespace App\Observers;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;

/**
 * Invalidation lives here rather than in the controllers so a controller
 * added later cannot forget to bust the cache. `saved` covers both create
 * and update; `deleted` and `restored` cover the soft-delete lifecycle.
 */
class ProductObserver
{
    public function saved(Product $product): void
    {
        $this->forget($product);
    }

    public function deleted(Product $product): void
    {
        $this->forget($product);
    }

    public function restored(Product $product): void
    {
        $this->forget($product);
    }

    private function forget(Product $product): void
    {
        Cache::forget("product:{$product->id}");
    }
}
