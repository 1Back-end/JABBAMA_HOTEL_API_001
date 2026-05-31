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
        Schema::table('virtual_orders_menu_restaurants', function (Blueprint $table) {
            $table->uuid('warehouse_uuid');
            $table->foreign('warehouse_uuid')
                ->references('uuid')
                ->on('warehouses')
                ->cascadeOnDelete();
            $table->index('warehouse_uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('virtual_orders_menu_restaurants', function (Blueprint $table) {
            $table->dropForeign('virtual_orders_menu_restaurants_warehouse_uuid_foreign');
            $table->dropIndex('virtual_orders_menu_restaurants_warehouse_uuid_index');
            $table->dropColumn('warehouse_uuid');
        });
    }
};
