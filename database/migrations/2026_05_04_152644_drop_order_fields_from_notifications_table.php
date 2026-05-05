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
        Schema::table('notifications', function (Blueprint $table) {

            // 🔥 1. drop foreign key AVANT colonne
            $table->dropForeign('fk_notif_order_menu');

            // 🔥 2. drop colonnes
            if (Schema::hasColumn('notifications', 'order_menu_restaurant_uuid')) {
                $table->dropColumn('order_menu_restaurant_uuid');
            }

            if (Schema::hasColumn('notifications', 'order_menu_restaurant_item_uuid')) {
                $table->dropColumn('order_menu_restaurant_item_uuid');
            }

            if (Schema::hasColumn('notifications', 'status')) {
                $table->dropColumn('status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('order_menu_restaurant_uuid')->nullable();
            $table->string('order_menu_restaurant_item_uuid')->nullable();
            $table->string('status')->nullable();
        });
    }
};
