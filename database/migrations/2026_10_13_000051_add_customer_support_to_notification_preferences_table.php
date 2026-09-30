<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rebuilds the table rather than using `->nullable()->change()` on
 * `user_id` — this app doesn't have `doctrine/dbal` installed, which
 * Laravel's column-modification migrations require, and pulling it in
 * as a dependency just for one column change isn't worth it. A
 * create-copy-drop-rename sequence needs nothing beyond what every
 * other migration in this app already uses, and works identically on
 * SQLite (tests) and MySQL (production).
 *
 * `customer_id` is nullable and independent of `user_id` — exactly one
 * of the two is ever set on a given row (enforced in
 * NotificationPreferenceService, not the schema), matching how a
 * notification's own `notifiable_type`/`notifiable_id` already lets
 * Laravel's stock notifications table serve both audiences. A separate
 * unique index per owner column is required (rather than one combined
 * index) because SQL treats NULL as distinct from NULL for uniqueness —
 * a single `unique(user_id, customer_id, event_key, channel)` index
 * would silently allow duplicate customer rows (every staff row's
 * customer_id is NULL) and vice versa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences_new', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();
            $table->string('event_key');
            $table->string('channel');
            $table->boolean('enabled')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'event_key', 'channel']);
            $table->unique(['customer_id', 'event_key', 'channel']);
        });

        DB::statement(
            'INSERT INTO notification_preferences_new (id, user_id, event_key, channel, enabled, created_at, updated_at) '.
            'SELECT id, user_id, event_key, channel, enabled, created_at, updated_at FROM notification_preferences'
        );

        Schema::drop('notification_preferences');
        Schema::rename('notification_preferences_new', 'notification_preferences');
    }

    public function down(): void
    {
        Schema::create('notification_preferences_old', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_key');
            $table->string('channel');
            $table->boolean('enabled')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'event_key', 'channel']);
        });

        // Any customer-owned rows have no home in the pre-Phase-10 shape
        // and are dropped, same as the original migration's own down().
        DB::statement(
            'INSERT INTO notification_preferences_old (id, user_id, event_key, channel, enabled, created_at, updated_at) '.
            'SELECT id, user_id, event_key, channel, enabled, created_at, updated_at FROM notification_preferences WHERE user_id IS NOT NULL'
        );

        Schema::drop('notification_preferences');
        Schema::rename('notification_preferences_old', 'notification_preferences');
    }
};
