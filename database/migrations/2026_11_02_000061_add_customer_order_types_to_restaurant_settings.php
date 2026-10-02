<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the customer app offers: Delivery and/or Takeaway (never dine-in —
     * that is for staff). Starts from what each restaurant already allowed.
     */
    public function up(): void
    {
        Schema::table('restaurant_settings', function (Blueprint $table) {
            $table->json('customer_order_types')->nullable()->after('order_types');
        });

        DB::table('restaurant_settings')->orderBy('id')->each(function ($row) {
            $existing = json_decode($row->order_types ?? 'null', true) ?: [];
            $types = array_values(array_intersect(['DELIVERY', 'TAKEAWAY'], $existing));
            if ($row->delivery_enabled && ! in_array('DELIVERY', $types, true)) {
                $types[] = 'DELIVERY';
            }
            if ($types === []) {
                $types = ['DELIVERY', 'TAKEAWAY'];
            }

            DB::table('restaurant_settings')->where('id', $row->id)->update([
                'customer_order_types' => json_encode(array_values($types)),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_settings', function (Blueprint $table) {
            $table->dropColumn('customer_order_types');
        });
    }
};
