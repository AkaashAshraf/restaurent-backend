<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opening hours per kind of service. A row with service GENERAL is the
 * branch's normal opening hours (every row that existed before this
 * migration); DINE_IN / TAKEAWAY / DELIVERY rows are optional overrides — a
 * service without rows simply follows the general hours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_hours', function (Blueprint $table) {
            $table->string('service', 20)->default('GENERAL')->after('restaurant_id');
        });

        // New unique first: it also starts with branch_id, so the foreign key
        // keeps an index while the old one is dropped.
        Schema::table('branch_hours', function (Blueprint $table) {
            $table->unique(['branch_id', 'service', 'day_of_week'], 'branch_hours_branch_service_day_unique');
        });

        Schema::table('branch_hours', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::table('branch_hours', function (Blueprint $table) {
            $table->unique(['branch_id', 'day_of_week']);
        });

        DB::table('branch_hours')->where('service', '!=', 'GENERAL')->delete();

        Schema::table('branch_hours', function (Blueprint $table) {
            $table->dropUnique('branch_hours_branch_service_day_unique');
            $table->dropColumn('service');
        });
    }
};
