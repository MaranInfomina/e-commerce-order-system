<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // A default admin so a clean clone has a way to reach the
        // admin-only screens (product create, order status, sales report)
        // without a manual `tinker` step - registration itself can never
        // produce one (RegisterRequest deliberately drops any submitted
        // role). Not idempotent, like the rest of this seeder: it's built
        // for `migrate:fresh --seed` / the guarded `app:seed-if-empty`, not
        // a repeatable `db:seed`.
        User::factory()->admin()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'correct-horse-battery',
        ]);

        $categories = Category::factory()->count(6)->create();

        foreach ($categories as $category) {
            Product::factory()->count(8)->for($category)->create();
        }

        // A couple of inactive products so the is_active filter has something
        // to exclude during the walkthrough.
        Product::factory()->count(2)->inactive()->for($categories->first())->create();
    }
}
