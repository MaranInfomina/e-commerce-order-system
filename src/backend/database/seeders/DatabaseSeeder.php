<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class DatabaseSeeder extends Seeder
{
    /**
     * Real category and product names, not ProductFactory/CategoryFactory's
     * usual fake()->words() output — this seeder is what a reviewer actually
     * looks at when they open the catalog, so "Deserunt Rerum" reads as
     * broken in a way a factory-random name in a test's own fixture data
     * never does. The factories themselves are untouched: dozens of tests
     * rely on fake()->unique() to create several categories/products per
     * test without colliding on the unique slug/sku columns, which a fixed
     * name list can't provide.
     *
     * The last two entries under "Electronics" only exist to give the two
     * inactive demo products (below) a real name instead of reusing an
     * active one and colliding on slug.
     *
     * @var array<string, array<int, array{name: string, description: string}>>
     */
    private const CATALOG = [
        'Electronics' => [
            ['name' => 'Wireless Bluetooth Headphones', 'description' => 'Over-ear headphones with active noise cancellation and up to 30 hours of battery life.'],
            ['name' => 'Portable Power Bank', 'description' => 'A 20,000mAh power bank with dual USB-C ports for fast-charging phones and tablets on the go.'],
            ['name' => '4K Ultra HD Monitor', 'description' => 'A 27-inch 4K IPS display with HDR support, ideal for both work and entertainment.'],
            ['name' => 'Mechanical Keyboard', 'description' => 'A tactile mechanical keyboard with hot-swappable switches and per-key RGB lighting.'],
            ['name' => 'USB-C Hub Adapter', 'description' => 'A 7-in-1 USB-C hub with HDMI, an SD card reader, and 100W power delivery pass-through.'],
            ['name' => 'Smart Home Speaker', 'description' => 'A voice-controlled smart speaker with rich bass and built-in home automation support.'],
            ['name' => 'Noise-Cancelling Earbuds', 'description' => 'True wireless earbuds with adaptive noise cancellation and a compact charging case.'],
            ['name' => 'Wireless Charging Pad', 'description' => 'A slim 15W wireless charging pad compatible with most Qi-enabled phones.'],
            ['name' => 'Bluetooth Fitness Tracker', 'description' => 'A lightweight fitness band that tracks heart rate, sleep, and daily activity.'],
            ['name' => 'Compact Digital Camera', 'description' => 'A point-and-shoot digital camera with a 20-megapixel sensor and optical zoom.'],
        ],
        'Home & Kitchen' => [
            ['name' => 'Stainless Steel Cookware Set', 'description' => 'A 10-piece stainless steel cookware set with tempered glass lids, oven-safe up to 500°F.'],
            ['name' => 'Programmable Coffee Maker', 'description' => 'A 12-cup programmable coffee maker with a built-in grinder and auto shut-off.'],
            ['name' => 'Non-Stick Frying Pan', 'description' => 'A durable non-stick frying pan with an ergonomic handle, safe for all stovetops.'],
            ['name' => 'Electric Kettle', 'description' => 'A 1.7-liter electric kettle that boils water in under five minutes with auto shut-off.'],
            ['name' => 'Ceramic Dinnerware Set', 'description' => 'A 16-piece ceramic dinnerware set in a matte glaze finish, microwave and dishwasher safe.'],
            ['name' => 'Knife Block Set', 'description' => 'A 15-piece kitchen knife set with a wooden block and a built-in sharpener.'],
            ['name' => 'Stand Mixer', 'description' => 'A 5-quart stand mixer with 10 speed settings and multiple attachments for baking.'],
            ['name' => 'Food Storage Container Set', 'description' => 'A 24-piece airtight food storage container set, stackable and freezer safe.'],
        ],
        'Outdoor & Sporting Goods' => [
            ['name' => 'Two-Person Camping Tent', 'description' => 'A lightweight two-person tent with a waterproof rainfly that sets up in minutes.'],
            ['name' => 'Insulated Stainless Water Bottle', 'description' => 'A double-wall insulated bottle that keeps drinks cold for 24 hours or hot for 12.'],
            ['name' => 'Hiking Backpack', 'description' => 'A 40-liter hiking backpack with a padded hip belt and multiple compression straps.'],
            ['name' => 'Yoga Mat', 'description' => 'A non-slip yoga mat with extra cushioning, includes a carrying strap.'],
            ['name' => 'Adjustable Dumbbell Set', 'description' => 'A pair of adjustable dumbbells that replace 15 sets of weights in one compact design.'],
            ['name' => 'Folding Camping Chair', 'description' => 'A lightweight folding chair with a cup holder and carry bag, rated for 300 lbs.'],
            ['name' => 'Sleeping Bag', 'description' => 'A 3-season sleeping bag rated to 20°F, with a compression sack for easy packing.'],
            ['name' => 'Bicycle Helmet', 'description' => 'An adjustable bicycle helmet with 14 vents and a rear safety light mount.'],
        ],
        'Apparel' => [
            ['name' => "Men's Cotton T-Shirt", 'description' => 'A soft, breathable 100% cotton t-shirt available in a classic fit.'],
            ['name' => "Women's Running Shoes", 'description' => 'Lightweight running shoes with responsive cushioning and a breathable mesh upper.'],
            ['name' => 'Denim Jacket', 'description' => 'A classic denim jacket with a button front and chest pockets.'],
            ['name' => 'Wool Winter Scarf', 'description' => 'A soft wool-blend scarf that adds warmth and style to any winter outfit.'],
            ['name' => 'Athletic Joggers', 'description' => 'Tapered athletic joggers with moisture-wicking fabric and zip pockets.'],
            ['name' => 'Leather Belt', 'description' => 'A genuine leather belt with a classic buckle, available in multiple sizes.'],
            ['name' => 'Rain Jacket', 'description' => 'A waterproof, packable rain jacket with an adjustable hood.'],
            ['name' => 'Baseball Cap', 'description' => 'An adjustable cotton baseball cap with an embroidered front panel.'],
        ],
        'Books' => [
            ['name' => 'The Art of Programming', 'description' => 'A practical guide to writing clean, maintainable code for developers at any level.'],
            ['name' => 'Modern Web Design', 'description' => 'An overview of contemporary web design principles, from layout to accessibility.'],
            ['name' => 'Cooking for Beginners', 'description' => 'Simple, step-by-step recipes for anyone just getting started in the kitchen.'],
            ['name' => 'A History of Science', 'description' => 'A sweeping look at the discoveries that shaped the modern scientific world.'],
            ['name' => 'Mindfulness Journal', 'description' => 'A guided journal with daily prompts for reflection and stress relief.'],
            ['name' => 'Personal Finance 101', 'description' => 'A beginner-friendly guide to budgeting, saving, and building long-term wealth.'],
            ['name' => "The Traveler's Guide to Europe", 'description' => 'An essential companion for planning a first trip across Europe.'],
            ['name' => 'Creative Writing Workshop', 'description' => 'Exercises and techniques to help writers develop their own unique voice.'],
        ],
        'Toys & Games' => [
            ['name' => 'Building Block Set', 'description' => 'A 500-piece building block set compatible with most major brands.'],
            ['name' => 'Wooden Jigsaw Puzzle', 'description' => 'A 1000-piece wooden jigsaw puzzle featuring a detailed landscape scene.'],
            ['name' => 'Remote Control Car', 'description' => 'A fast, durable remote control car built for both indoor and outdoor play.'],
            ['name' => 'Classic Board Game', 'description' => 'A family board game for 2-6 players, easy to learn and fun for all ages.'],
            ['name' => 'Plush Teddy Bear', 'description' => 'A soft, huggable plush teddy bear made from hypoallergenic materials.'],
            ['name' => 'Art Supply Kit', 'description' => 'A complete art kit with colored pencils, markers, and a sketchpad.'],
            ['name' => 'Model Rocket Kit', 'description' => 'A beginner-friendly model rocket kit that launches up to 500 feet.'],
            ['name' => 'Strategy Card Game', 'description' => 'A fast-paced strategy card game for 2-4 players, easy to learn, hard to master.'],
        ],
    ];

    /** How many of "Electronics"'s entries above are the inactive demo products, kept last. */
    private const INACTIVE_COUNT = 2;

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

        foreach (self::CATALOG as $categoryName => $products) {
            $category = Category::factory()->create([
                'name' => $categoryName,
                'slug' => Str::slug($categoryName),
            ]);

            $activeCount = $categoryName === 'Electronics'
                ? count($products) - self::INACTIVE_COUNT
                : count($products);

            foreach ($products as $index => $entry) {
                $factory = Product::factory()->for($category);

                if ($index >= $activeCount) {
                    $factory = $factory->inactive();
                }

                $product = $factory->create([
                    'name' => $entry['name'],
                    'slug' => Str::slug($entry['name']),
                    'description' => $entry['description'],
                ]);

                $this->attachImage($product);
            }
        }
    }

    /**
     * Fetches a real photo from Lorem Picsum (picsum.photos) - free, no API
     * key, no attribution required - keyed by the product's own slug so the
     * same product always gets the same photo across a re-seed. Storage
     * follows the exact same disk/key convention ProductImageController
     * uses for a real upload, so `image_url` resolves identically either
     * way.
     *
     * Best-effort: seeding must still succeed on a machine with no internet
     * access (CR-3's "a clean clone just boots" applies here too) or if
     * Picsum is unreachable - a product is simply left with no image, the
     * same as one nobody ever uploaded a photo for.
     *
     * Skipped entirely under the test suite. SeedIfEmptyTest calls
     * `artisan('app:seed-if-empty')` directly - which runs this seeder in
     * full - so without this guard, every one of those calls fetches 50
     * real images over the network. That tripled the suite's runtime the
     * first time this ran and made the suite's speed depend on Picsum being
     * reachable at all, for a demo image nothing in the test suite ever
     * asserts on.
     */
    private function attachImage(Product $product): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        try {
            $response = Http::timeout(5)->get("https://picsum.photos/seed/{$product->slug}/800/800");

            if (! $response->successful() || $response->body() === '') {
                return;
            }

            $key = "products/{$product->id}/seed.jpg";
            Storage::disk('s3')->put($key, $response->body());
            $product->update(['image_path' => $key]);
        } catch (Throwable $e) {
            Log::warning('Seeder could not fetch a product image', [
                'product_id' => $product->id,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
