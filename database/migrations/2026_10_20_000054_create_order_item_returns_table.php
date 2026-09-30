<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            // The monetary amount this return deducted from the order's
            // subtotal/total at the time it was recorded (quantity ×
            // that line's unit_price + modifiers_total) — kept as its
            // own column rather than recomputed later, since the
            // product's price can change after the fact.
            $table->decimal('amount', 12, 2);
            $table->string('reason');
            $table->foreignId('returned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['restaurant_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_returns');
    }
};
