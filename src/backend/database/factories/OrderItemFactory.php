<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        $unitPriceCents = fake()->numberBetween(500, 25000);
        $quantity = fake()->numberBetween(1, 5);

        return [
            'order_id' => Order::factory(),
            'product_id' => Product::factory(),
            'product_name' => fake()->words(3, true),
            'product_sku' => strtoupper(fake()->bothify('???-####')),
            'product_image_path' => null,
            'unit_price_cents' => $unitPriceCents,
            'quantity' => $quantity,
            'line_total_cents' => $unitPriceCents * $quantity,
        ];
    }
}
