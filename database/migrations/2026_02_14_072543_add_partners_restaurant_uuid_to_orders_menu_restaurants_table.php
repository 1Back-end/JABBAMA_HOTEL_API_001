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
        Schema::table('orders_menu_restaurants', function (Blueprint $table) {
            $table->uuid('partners_restaurant_uuid')->nullable()->after('type_clients_for_payment');
            $table->foreign('partners_restaurant_uuid')
                ->references('uuid')
                ->on('restaurant_partners')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders_menu_restaurants', function (Blueprint $table) {
            $table->dropForeign(['partners_restaurant_uuid']);
            $table->dropColumn('partners_restaurant_uuid');
        });
    }
};
