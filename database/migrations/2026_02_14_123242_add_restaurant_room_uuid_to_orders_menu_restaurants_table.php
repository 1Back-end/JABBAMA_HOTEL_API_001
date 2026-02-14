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
            $table->uuid('restaurant_room_uuid')
                ->nullable()
                ->after('restaurant_table_uuid');

            $table->foreign('restaurant_room_uuid')
                ->references('uuid')
                ->on('restaurant_rooms')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders_menu_restaurants', function (Blueprint $table) {
            $table->dropForeign(['restaurant_room_uuid']);
            $table->dropColumn('restaurant_room_uuid');
        });
    }
};
