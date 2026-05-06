<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {

            // 🔥 timestamp manquant
            if (!Schema::hasColumn('orders_menu_restaurant_items', 'cancel_for_new_update_at')) {
                $table->timestamp('cancel_for_new_update_at')->nullable()->after('cancel_for_new_update_by');
            }

            // 🔥 sécurité si champ reason existe déjà ou pas
            if (!Schema::hasColumn('orders_menu_restaurant_items', 'reason_of_cancel_for_new_update')) {
                $table->string('reason_of_cancel_for_new_update')->nullable()->after('cancel_for_new_update_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {

            $columns = [
                'cancel_for_new_update_at',
                'reason_of_cancel_for_new_update'
            ];

            foreach ($columns as $col) {
                if (Schema::hasColumn('orders_menu_restaurant_items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
