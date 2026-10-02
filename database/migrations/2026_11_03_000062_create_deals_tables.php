<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A deal is a combo: a fixed price for a set of menu items.
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('image', 2048)->nullable();
            $table->decimal('price', 12, 2);
            $table->string('status')->default('ACTIVE'); // ACTIVE | INACTIVE
            $table->date('starts_on')->nullable();       // restaurant's local date; null = no start
            $table->date('ends_on')->nullable();         // inclusive; null = no end
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['restaurant_id', 'status']);
        });

        Schema::create('deal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();

            $table->unique(['deal_id', 'product_id']);
        });

        // Which deal an order line came from, so the order can still read "Family Deal".
        // `deal_ref` is shared by every line of one deal in one order.
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('deal_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
            $table->string('deal_name')->nullable()->after('deal_id');
            $table->string('deal_ref', 36)->nullable()->after('deal_name');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deal_id');
            $table->dropColumn(['deal_name', 'deal_ref']);
        });
        Schema::dropIfExists('deal_items');
        Schema::dropIfExists('deals');
    }
};
