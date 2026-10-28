<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('statistics_orders_status_menus_restaurants', function (Blueprint $table) {

            if (!Schema::hasColumn('statistics_orders_status_menus_restaurants', 'cancel_for_new_update_by')) {
                $table->unsignedBigInteger('cancel_for_new_update_by')->nullable()->after('cancel_for_new_update_at');

                $table->foreign('cancel_for_new_update_by', 'stat_cancel_update_user_fk')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('statistics_orders_status_menus_restaurants', function (Blueprint $table) {
            if (Schema::hasColumn('statistics_orders_status_menus_restaurants', 'cancel_for_new_update_by')) {
                $table->dropForeign('stat_cancel_update_user_fk');
                $table->dropColumn('cancel_for_new_update_by');
            }
        });
    }
};
