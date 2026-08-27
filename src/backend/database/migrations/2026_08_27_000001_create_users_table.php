<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            // bcrypt output is 60 characters; 255 leaves room for a future
            // algorithm change without a migration.
            $table->string('password');
            $table->string('role', 16)->default('customer');
            $table->timestamps();
        });

        // A checked varchar rather than a native Postgres enum: enums need a
        // migration to extend, and Milestone 3 may add roles. Named so a
        // violation is diagnosable, matching the products table's style.
        DB::statement(
            "ALTER TABLE users ADD CONSTRAINT users_role_allowed
             CHECK (role IN ('customer', 'admin'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
