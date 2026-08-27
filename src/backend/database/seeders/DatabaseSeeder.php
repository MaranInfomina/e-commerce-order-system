<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $categories = Category::factory()->count(6)->create();

        foreach ($categories as $category) {
            Product::factory()->count(8)->for($category)->create();
        }

        // A couple of inactive products so the is_active filter has something
        // to exclude during the walkthrough.
        Product::factory()->count(2)->inactive()->for($categories->first())->create();
    }
}
