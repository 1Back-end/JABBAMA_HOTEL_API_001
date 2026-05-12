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
            if (Schema::hasColumn('statistics_orders_status_drinks', 'product_uuid')) {
                $table->dropForeign('sosd_prod_fk');
                $table->dropIndex('sosd_prod_fk');
                $table->dropColumn('product_uuid');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('statistics_orders_status_drinks', function (Blueprint $table) {
            if (!Schema::hasColumn('statistics_orders_status_drinks', 'product_uuid')) {

                $table->uuid('product_uuid')
                    ->nullable()
                    ->after('order_menu_restaurant_uuid');

                $table->index('product_uuid', 'sosd_prod_fk');

                $table->foreign('product_uuid', 'sosd_prod_fk')
                    ->references('uuid')
                    ->on('produits')
                    ->cascadeOnDelete();
            }
        });
    }
};
