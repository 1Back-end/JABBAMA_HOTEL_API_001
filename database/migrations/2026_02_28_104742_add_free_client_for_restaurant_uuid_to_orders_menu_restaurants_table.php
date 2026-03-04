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
            $table->uuid('free_client_for_restaurant_uuid')->nullable()->after('restaurant_table_uuid')->comment('UUID du client gratuit lié à cette commande');

            $table->foreign('free_client_for_restaurant_uuid')->references('uuid')->on('free_clients_restaurants')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders_menu_restaurants', function (Blueprint $table) {
            //
        });
    }
};
