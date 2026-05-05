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
            $table->timestamp('rejected_after_validation_at')->nullable()->after('rejected_by');
            $table->string('reason_of_rejected_after_validation')->nullable()->after('rejected_at');
            $table->foreignId('rejected_after_validation_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_reason_of_cancel_for_new_update')->default(false)->after('rejected_after_validation_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {
            $table->dropForeign(['rejected_after_validation_by']);
            $table->dropColumn([
                'rejected_after_validation_by',
                'rejected_after_validation_at',
                'reason_of_rejected_after_validation',
                'is_reason_of_cancel_for_new_update'
            ]);
        });
    }
};
