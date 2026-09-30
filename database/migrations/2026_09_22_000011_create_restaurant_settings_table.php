<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('order_types')->nullable(); // ["DINE_IN","TAKEAWAY","DELIVERY"]
            $table->decimal('min_order_amount', 12, 2)->default(0);
            $table->unsignedInteger('default_prep_time_minutes')->default(15);
            $table->boolean('tax_enabled')->default(false);
            $table->decimal('tax_percentage', 5, 2)->default(0);
            $table->boolean('tax_inclusive')->default(false);
            $table->boolean('delivery_enabled')->default(false);
            $table->decimal('delivery_fee', 12, 2)->default(0);
            $table->decimal('free_delivery_threshold', 12, 2)->nullable();
            $table->string('order_number_scheme')->default('RESTAURANT'); // RESTAURANT | BRANCH
            $table->boolean('order_number_daily_reset')->default(false);
            $table->string('branch_selection_mode')->default('BOTH'); // AUTOMATIC | MANUAL | BOTH
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_settings');
    }
};
