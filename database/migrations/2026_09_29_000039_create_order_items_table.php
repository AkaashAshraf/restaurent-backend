<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // nullOnDelete rather than cascade: a product can be deleted long
            // after old orders reference it, but the order history must
            // survive — hence the product_name/unit_price snapshots below.
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name'); // snapshot at order time
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2); // product price at this branch, at order time (excludes modifiers)
            $table->decimal('modifiers_total', 12, 2)->default(0); // per unit
            $table->decimal('line_total', 12, 2); // (unit_price + modifiers_total) * quantity
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['restaurant_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
