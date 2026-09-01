<?php

namespace App\Observers;

use App\Models\Category;
use Illuminate\Support\Facades\Cache;

class CategoryObserver
{
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
