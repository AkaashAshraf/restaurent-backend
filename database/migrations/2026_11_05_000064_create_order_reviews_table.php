<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One review per completed order: stars (1-5) and an optional comment for the
        // food, the rider (delivery only) and the app itself.
        Schema::create('order_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('rider_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('food_rating')->nullable();
            $table->string('food_review', 1000)->nullable();
            $table->unsignedTinyInteger('rider_rating')->nullable();
            $table->string('rider_review', 1000)->nullable();
            $table->unsignedTinyInteger('app_rating')->nullable();
            $table->string('app_review', 1000)->nullable();
            $table->timestamps();

            $table->index(['restaurant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_reviews');
    }
};
