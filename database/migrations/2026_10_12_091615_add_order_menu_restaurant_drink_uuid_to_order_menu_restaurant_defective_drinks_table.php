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
        Schema::table('order_menu_restaurant_defective_drinks', function (Blueprint $table) {
            if (!Schema::hasColumn('order_menu_restaurant_defective_drinks', 'order_menu_restaurant_drink_uuid')) {
                $table->uuid('order_menu_restaurant_drink_uuid')->nullable()->after('uuid');

                $table->foreign('order_menu_restaurant_drink_uuid', 'def_drink_uuid_foreign')
                    ->references('uuid')
                    ->on('order_restaurannts_drinks')
                    ->onDelete('cascade');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_menu_restaurant_defective_drinks', function (Blueprint $table) {
            if (Schema::hasColumn('order_menu_restaurant_defective_drinks', 'order_menu_restaurant_drink_uuid')) {
                $table->dropForeign('def_drink_uuid_foreign');
                $table->dropColumn('order_menu_restaurant_drink_uuid');
            }
        });
    }
};
