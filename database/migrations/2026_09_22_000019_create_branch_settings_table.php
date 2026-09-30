<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->json('order_types')->nullable(); // null = inherit restaurant default
            $table->boolean('delivery_enabled')->nullable(); // null = inherit
            $table->decimal('min_order_amount', 12, 2)->nullable();
            $table->decimal('free_delivery_threshold', 12, 2)->nullable();
            $table->unsignedInteger('prep_time_minutes')->nullable();
            $table->unsignedInteger('max_delivery_distance_km')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_settings');
    }
};
