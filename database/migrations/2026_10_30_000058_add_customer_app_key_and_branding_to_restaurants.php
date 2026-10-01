<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            // The key the super admin hands to a restaurant's own customer app. The app
            // sends it on every public request (X-App-Key) and the server answers with
            // that restaurant's branding and data — one backend, one database, one
            // customer app per restaurant. null = the restaurant has no customer app.
            $table->string('app_key', 64)->nullable()->unique()->after('slug');
            // Everything visual / textual about that app (name, colours, logo, banners...).
            $table->json('app_branding')->nullable()->after('theme');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropUnique(['app_key']);
            $table->dropColumn(['app_key', 'app_branding']);
        });
    }
};
