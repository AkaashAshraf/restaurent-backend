<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // e.g. order.placed, order.status_changed, order.rider_assigned, payment.received
            $table->string('event_key');
            // mail | sms | push — `database` is not represented here, it's always on
            $table->string('channel');
            $table->boolean('enabled')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'event_key', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
