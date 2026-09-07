<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // RESTRICT, not CASCADE: an order must never lose its line items
            // because the underlying product was later hard-deleted. Products
            // are soft-deleted regardless, but the constraint holds even if
            // that ever changes.
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            // Snapshots, not joins — a renamed, repriced, re-imaged, or
            // deleted product must not alter how an existing order reads.
            $table->string('product_name');
            $table->string('product_sku', 64);
            // The image key, not the object itself. Nullable because a
            // product with no image at checkout time snapshots no path.
            $table->string('product_image_path', 512)->nullable();
            $table->integer('unit_price_cents');
            $table->integer('quantity');
            $table->integer('line_total_cents');
            $table->timestamps();

            $table->index('order_id');
        });

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_unit_price_cents_non_negative CHECK (unit_price_cents >= 0)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_quantity_positive CHECK (quantity > 0)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_line_total_cents_non_negative CHECK (line_total_cents >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
