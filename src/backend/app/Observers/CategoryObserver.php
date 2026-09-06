<?php

namespace App\Observers;

use App\Models\Category;
use Illuminate\Support\Facades\Cache;

class CategoryObserver
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

    public function saved(Category $category): void
    {
        Cache::forget('categories:all');

        // ProductResource embeds the category's name and slug INSIDE every
        // cached product, so product:{id} depends on the category row too —
        // it has two invalidation triggers, not one. Without this, renaming a
        // category leaves every cached product in it serving the old name for
        // the full hour with nothing to bust it.
        //
        // Only on an actual name/slug change: a create has no products yet,
        // and touching a category for any other reason should not flush its
        // whole catalogue.
        if ($category->wasChanged(['name', 'slug'])) {
            $category->products()->pluck('id')->each(
                fn (int $id) => Cache::forget("product:{$id}")
            );
        }
    }

    public function deleted(Category $category): void
    {
        // A category with products cannot be deleted at all — products.category_id
        // is constrained with restrictOnDelete — so there are no cached
        // products to bust here.
        Cache::forget('categories:all');
    }
}
