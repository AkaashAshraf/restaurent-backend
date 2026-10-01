<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers can sign in with Google or Apple, which hand over an email but no
 * phone number — so the phone becomes optional here and is collected before
 * the customer's first order instead. (Unique (restaurant_id, phone) stays:
 * any number of customers may have no phone yet, no two may share one.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('phone')->nullable()->change();
            $table->string('google_id', 64)->nullable()->after('email');
            $table->string('apple_id', 64)->nullable()->after('google_id');

            $table->unique(['restaurant_id', 'google_id']);
            $table->unique(['restaurant_id', 'apple_id']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['restaurant_id', 'google_id']);
            $table->dropUnique(['restaurant_id', 'apple_id']);
            $table->dropColumn(['google_id', 'apple_id']);
            // phone stays nullable: putting NOT NULL back would fail on social-only rows.
        });
    }
};
