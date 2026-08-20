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
            $table->uuid('room_service_uuid')->nullable()->after('uuid');
            $table->integer('price_for_room_service')->default(0)->nullable()->after('room_service_uuid');
            $table->boolean('is_room_service')->default(false)->after('price_for_room_service');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders_menu_restaurants', function (Blueprint $table) {
            $table->dropColumn(['room_service_uuid', 'price_for_room_service', 'is_room_service']);
        });
    }
};
