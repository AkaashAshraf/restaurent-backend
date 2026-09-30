<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('restaurant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->string('phone')->nullable()->after('email');
            $table->boolean('is_super_admin')->default(false)->after('phone');
            // ACTIVE | INACTIVE
            $table->string('status')->default('ACTIVE')->after('is_super_admin');
            $table->timestamp('last_login_at')->nullable()->after('status');
            $table->softDeletes();

            $table->index(['restaurant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('restaurant_id');
            $table->dropColumn(['phone', 'is_super_admin', 'status', 'last_login_at', 'deleted_at']);
        });
    }
};
