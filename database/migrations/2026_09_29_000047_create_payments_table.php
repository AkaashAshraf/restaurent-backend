<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // CASH | CARD | ONLINE
            $table->string('method');
            // PENDING -> PAID | FAILED, or PAID -> REFUNDED
            $table->string('status')->default('PENDING');
            $table->decimal('amount', 12, 2);
            // Set by the gateway for an ONLINE payment (mocked in this
            // phase — no real gateway integration, see PaymentService); a
            // staff-supplied free-text reference for CASH/CARD (e.g. a
            // receipt or terminal slip number).
            $table->string('transaction_reference')->nullable();
            // Null for a customer-initiated ONLINE payment; set for a
            // CASH/CARD payment a staff member recorded on the customer's
            // behalf.
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['restaurant_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
