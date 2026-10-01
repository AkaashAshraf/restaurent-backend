<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_settings', function (Blueprint $table) {
            // Tax by how the customer pays (e.g. Pakistan: a lower sales-tax rate on card
            // payments). null = fall back to tax_percentage.
            $table->decimal('cash_tax_percentage', 5, 2)->nullable()->after('tax_percentage');
            $table->decimal('card_tax_percentage', 5, 2)->nullable()->after('cash_tax_percentage');
            // Which kinds of orders appear on the kitchen screen. null = all of them.
            $table->json('kitchen_order_types')->nullable();
            // Printed on every bill.
            $table->string('fbr_number', 60)->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            // The rate the tax on this order was worked out at, and — once the first
            // payment is taken — which payment method fixed it. Plus the FBR number
            // as it was when the order was placed, so an old bill never changes.
            $table->decimal('tax_rate', 5, 2)->nullable()->after('tax_amount');
            $table->string('tax_method', 10)->nullable()->after('tax_rate');
            $table->string('fbr_number', 60)->nullable()->after('tax_method');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['tax_rate', 'tax_method', 'fbr_number']);
        });

        Schema::table('restaurant_settings', function (Blueprint $table) {
            $table->dropColumn(['cash_tax_percentage', 'card_tax_percentage', 'kitchen_order_types', 'fbr_number']);
        });
    }
};
