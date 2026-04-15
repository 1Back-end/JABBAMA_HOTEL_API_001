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
        Schema::table('statistics_orders_status_drinks', function (Blueprint $table) {
            $table->uuid('order_restaurant_drink_uuid')
                ->nullable()
                ->after('uuid');

            // 🔥 FOREIGN KEY
            $table->foreign('order_restaurant_drink_uuid', 'sosd_drink_fk')
                ->references('uuid')
                ->on('order_restaurannts_drinks') // ⚠️ vérifie bien le nom de table
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('statistics_orders_status_drinks', function (Blueprint $table) {
            $table->dropForeign('sosd_drink_fk');
            $table->dropColumn('order_restaurant_drink_uuid');
        });
    }
};
