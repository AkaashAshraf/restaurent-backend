<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-branch override of a product's availability and/or price. A
     * missing row means "inherit the product's own status/base_price" —
     * this table only needs a row when a branch actually differs from the
     * restaurant-wide default (spec: branch-level menu availability/pricing).
     */
    public function up(): void
    {
        Schema::create('branch_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_available')->default(true);
            $table->decimal('price_override', 10, 2)->nullable(); // null = use product's base_price
            $table->timestamps();

            $table->unique(['branch_id', 'product_id']);
            $table->index(['restaurant_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_products');
    }
};
