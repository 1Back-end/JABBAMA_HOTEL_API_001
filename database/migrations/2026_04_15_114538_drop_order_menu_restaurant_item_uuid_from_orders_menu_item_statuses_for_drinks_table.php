<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders_menu_item_statuses_for_drinks', function (Blueprint $table) {

            // 🔥 DROP FK AVEC NOM EXACT
            try {
                $table->dropForeign('omisfd_item_fk');
            } catch (\Throwable $e) {
                // FK déjà supprimée → ignore
            }

            $table->dropColumn('order_menu_restaurant_item_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('orders_menu_item_statuses_for_drinks', function (Blueprint $table) {

            $table->uuid('order_menu_restaurant_item_uuid')->nullable();

            $table->foreign('order_menu_restaurant_item_uuid', 'omisfd_item_fk')
                ->references('uuid')
                ->on('orders_menu_restaurant_items')
                ->cascadeOnDelete();
        });
    }
};
