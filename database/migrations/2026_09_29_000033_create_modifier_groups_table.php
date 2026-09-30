<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modifier_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // e.g. "Size", "Toppings"
            // SINGLE = radio (e.g. Size), MULTIPLE = checkboxes (e.g. Toppings)
            $table->string('selection_type')->default('SINGLE');
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('min_selections')->default(0);
            $table->unsignedInteger('max_selections')->nullable(); // null = unlimited
            $table->unsignedInteger('display_order')->default(100);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['restaurant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modifier_groups');
    }
};
