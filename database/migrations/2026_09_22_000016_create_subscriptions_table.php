<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_plan_id')->constrained();
            $table->date('start_date');
            $table->date('expiry_date')->nullable();
            // ACTIVE | EXPIRED | SUSPENDED | CANCELLED
            $table->string('status')->default('ACTIVE');
            $table->unsignedInteger('branch_limit_override')->nullable();
            $table->unsignedInteger('user_limit_override')->nullable();
            $table->timestamps();

            $table->index(['restaurant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
