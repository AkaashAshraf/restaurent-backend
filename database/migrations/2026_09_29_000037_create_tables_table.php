<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('table_number');
            $table->unsignedInteger('capacity')->nullable();
            $table->string('section')->nullable(); // e.g. "Patio", "Main Hall" — free text, no separate zones table for MVP
            // AVAILABLE | OCCUPIED | RESERVED | UNAVAILABLE
            $table->string('status')->default('AVAILABLE');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'table_number']);
            $table->index(['restaurant_id', 'branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tables');
    }
};
