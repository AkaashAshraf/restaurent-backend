<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plan_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // Explicit name: the default one is 65 characters, over MySQL's 64.
            $table->unique(['subscription_plan_id', 'feature_id'], 'plan_features_plan_feature_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plan_features');
    }
};
