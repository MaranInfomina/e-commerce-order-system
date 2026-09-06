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
    /**
     * Bust after the transaction commits, not at save time.
     *
     * Nothing in this milestone wraps a write in a transaction, so there is no
     * live bug — but the moment one does, `saved` would fire while the row is
     * still invisible to everyone else, a concurrent reader could re-cache the
     * pre-write value, and it would stay pinned for the full TTL.
     */
    public bool $afterCommit = true;

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
