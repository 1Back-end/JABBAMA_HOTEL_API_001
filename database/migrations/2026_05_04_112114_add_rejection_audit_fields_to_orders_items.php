<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {
            $table->timestamp('cancel_for_new_update_at')->nullable()->after('rejected_by');
            $table->string('reason_of_cancel_for_new_update')->nullable()->after('rejected_at');
            $table->foreignId('cancel_for_new_update_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {
            $table->dropForeign(['cancel_for_new_update_by']);
            $table->dropColumn([
                'cancel_for_new_update_by',
                'cancel_for_new_update_at',
                'reason_of_cancel_for_new_update'
            ]);
        });
    }
};
