<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('sku', 64)->unique();
            $table->text('description')->nullable();
            $table->integer('price_cents');
            $table->integer('stock_quantity')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            // PostgreSQL does not index foreign keys implicitly.
            $table->index('category_id');
            // Serves the default listing: active products, newest first.
            $table->index(['is_active', 'created_at']);
            // Serves price range filtering and price sorting.
            $table->index('price_cents');
        });

        DB::statement(
            'ALTER TABLE products ADD CONSTRAINT products_stock_quantity_non_negative '
            .'CHECK (stock_quantity >= 0)'
        );

        DB::statement(
            'ALTER TABLE products ADD CONSTRAINT products_price_cents_non_negative '
            .'CHECK (price_cents >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
