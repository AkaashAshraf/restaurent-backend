<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            // PERCENTAGE | FIXED
            $table->string('type');
            $table->decimal('value', 12, 2);
            $table->decimal('min_order_amount', 12, 2)->default(0);
            // Only meaningful for PERCENTAGE — caps the discount a large
            // order could otherwise generate. Null means uncapped.
            $table->decimal('max_discount_amount', 12, 2)->nullable();
            // Total redemptions allowed across every customer. Null = unlimited.
            $table->unsignedInteger('usage_limit')->nullable();
            // Redemptions allowed per customer. Null = unlimited; only ever
            // enforced when the order has a customer attached — a
            // staff-placed order with no customer_id only counts against
            // usage_limit.
            $table->unsignedInteger('per_customer_limit')->nullable();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['restaurant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
