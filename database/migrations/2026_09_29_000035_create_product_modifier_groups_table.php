<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which modifier groups apply to which product (e.g. Pizza -> Size,
     * Toppings). A pure pivot — no restaurant_id needed since both sides
     * already belong to the same tenant by construction, and we always
     * join through product_id in queries.
     */
    public function up(): void
    {
        Schema::create('product_modifier_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('modifier_group_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('display_order')->default(100);
            $table->timestamps();

            $table->unique(['product_id', 'modifier_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_modifier_groups');
    }
};
