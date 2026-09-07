<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('status', 16)->default('pending');
            $table->string('idempotency_key')->nullable();
            $table->text('shipping_address');
            $table->text('notes')->nullable();
            $table->integer('total_cents');
            $table->timestamps();

            $table->index('user_id');
            $table->index('created_at');
            // Postgres treats every NULL as distinct, so orders with no
            // idempotency key never collide with one another — this is what
            // makes per-user idempotency-key scoping a database-level
            // guarantee rather than an application-level promise.
            $table->unique(['user_id', 'idempotency_key']);
        });

        // varchar + CHECK, the same pattern users.role already established —
        // Postgres enums require a migration to extend, and an admin-action
        // path added later is exactly the kind of thing that might grow a
        // status later.
        DB::statement(
            "ALTER TABLE orders ADD CONSTRAINT orders_status_check ".
            "CHECK (status IN ('pending','paid','payment_failed','shipped','delivered'))"
        );
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_total_cents_non_negative CHECK (total_cents >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
