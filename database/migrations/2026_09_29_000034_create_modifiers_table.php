<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('modifier_group_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // e.g. "Large", "Extra Cheese"
            $table->decimal('price_adjustment', 10, 2)->default(0); // added to base_price when selected
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('display_order')->default(100);
            $table->string('status')->default('ACTIVE');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['restaurant_id', 'modifier_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modifiers');
    }
};
