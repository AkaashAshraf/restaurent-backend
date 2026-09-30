<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Links every order item to the kitchen ticket it was cooked on, and
 * gives every existing order its ticket 1 so nothing already in the
 * system disappears from (or wrongly reappears on) the kitchen screen:
 *
 *  - orders still in the kitchen (PENDING/CONFIRMED/PREPARING/READY) get
 *    a ticket with the same status;
 *  - orders already out of the kitchen (OUT_FOR_DELIVERY/COMPLETED) get a
 *    PICKED_UP ticket;
 *  - cancelled orders get a CANCELLED ticket.
 *
 * Uses the query builder rather than models on purpose: models carry the
 * tenant scope, which has no tenant set while migrations run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('kitchen_ticket_id')->nullable()->after('order_id');
            $table->index('kitchen_ticket_id');
        });

        DB::table('orders')->orderBy('id')->chunkById(200, function ($orders) {
            foreach ($orders as $order) {
                $status = match ($order->status) {
                    'PENDING', 'CONFIRMED', 'PREPARING', 'READY' => $order->status,
                    'CANCELLED' => 'CANCELLED',
                    default => 'PICKED_UP',
                };

                $ticketId = DB::table('kitchen_tickets')->insertGetId([
                    'restaurant_id' => $order->restaurant_id,
                    'branch_id' => $order->branch_id,
                    'order_id' => $order->id,
                    'sequence' => 1,
                    'status' => $status,
                    'ready_at' => in_array($status, ['READY', 'PICKED_UP'], true) ? $order->updated_at : null,
                    'picked_up_at' => $status === 'PICKED_UP' ? $order->updated_at : null,
                    'created_at' => $order->created_at,
                    'updated_at' => $order->updated_at,
                ]);

                DB::table('order_items')
                    ->where('order_id', $order->id)
                    ->update(['kitchen_ticket_id' => $ticketId]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['kitchen_ticket_id']);
            $table->dropColumn('kitchen_ticket_id');
        });
    }
};
