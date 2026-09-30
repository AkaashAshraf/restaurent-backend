<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // RADIUS: a circle around center_latitude/center_longitude out
            // to radius_km. POLYGON: an arbitrary shape stored in `polygon`.
            $table->string('type')->default('RADIUS');
            $table->decimal('center_latitude', 10, 7)->nullable();
            $table->decimal('center_longitude', 10, 7)->nullable();
            $table->decimal('radius_km', 6, 2)->nullable();
            // [{"lat": 24.86, "lng": 67.00}, ...] — only for type = POLYGON.
            $table->json('polygon')->nullable();
            // Overrides RestaurantSetting/BranchSetting's delivery_fee for
            // orders resolved into this zone; null = fall back to the
            // existing branch/restaurant default (see OrderService).
            $table->decimal('delivery_fee_override', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['branch_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_zones');
    }
};
