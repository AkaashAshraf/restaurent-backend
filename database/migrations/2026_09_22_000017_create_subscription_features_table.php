<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Snapshot/override of enabled features for a specific subscription.
        // Seeded from plan defaults when the subscription is created; Super Admin
        // can toggle individual rows afterwards without changing the plan.
        Schema::create('subscription_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['subscription_id', 'feature_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_features');
    }
};
