<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's standard database-notifications shape (the one
 * `php artisan notifications:table` generates) — `User` already has the
 * `Notifiable` trait from the framework's default scaffolding, so this
 * is the missing table it needs rather than a bespoke one. `notifiable`
 * (morphs) means this could target `Customer` too in a future phase
 * without a schema change; Phase 8 only ever notifies `User` (see
 * NotificationService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
