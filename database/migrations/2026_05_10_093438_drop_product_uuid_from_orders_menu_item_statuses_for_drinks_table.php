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
        Schema::table('orders_menu_item_statuses_for_drinks', function (Blueprint $table) {
            if (Schema::hasColumn('orders_menu_item_statuses_for_drinks', 'product_uuid')) {
                $table->dropForeign('omisfd_product_fk');
                $table->dropIndex('omisfd_product_fk');
                $table->dropColumn('product_uuid');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders_menu_item_statuses_for_drinks', function (Blueprint $table) {
            if (!Schema::hasColumn('orders_menu_item_statuses_for_drinks', 'product_uuid')) {

                $table->uuid('product_uuid')->nullable();

                $table->index('product_uuid', 'omisfd_product_fk');

                $table->foreign('product_uuid', 'omisfd_product_fk')
                    ->references('uuid')
                    ->on('produits')
                    ->cascadeOnDelete();
            }
        });
    }
};
