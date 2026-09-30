<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Same rebuild approach and reasoning as the notification_preferences migration alongside this one. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens_new', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();
            // Still globally unique across both audiences — re-registering
            // an existing token reassigns it to whoever just registered
            // it, staff or customer, clearing whichever owner column it
            // used to belong to (see DeviceTokenController::store()).
            $table->string('token')->unique();
            $table->string('platform');
            $table->timestamps();

            $table->index('user_id');
            $table->index('customer_id');
        });

        DB::statement(
            'INSERT INTO device_tokens_new (id, user_id, token, platform, created_at, updated_at) '.
            'SELECT id, user_id, token, platform, created_at, updated_at FROM device_tokens'
        );

        Schema::drop('device_tokens');
        Schema::rename('device_tokens_new', 'device_tokens');
    }

    public function down(): void
    {
        Schema::create('device_tokens_old', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token')->unique();
            $table->string('platform');
            $table->timestamps();

            $table->index('user_id');
        });

        DB::statement(
            'INSERT INTO device_tokens_old (id, user_id, token, platform, created_at, updated_at) '.
            'SELECT id, user_id, token, platform, created_at, updated_at FROM device_tokens WHERE user_id IS NOT NULL'
        );

        Schema::drop('device_tokens');
        Schema::rename('device_tokens_old', 'device_tokens');
    }
};
