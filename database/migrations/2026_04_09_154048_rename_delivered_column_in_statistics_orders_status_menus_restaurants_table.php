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
        Schema::table('statistics_orders_status_menus_restaurants', function (Blueprint $table) {
            $table->renameColumn('delivered', 'delivered_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('statistics_orders_status_menus_restaurants', function (Blueprint $table) {
            $table->renameColumn('delivered_at', 'delivered');
        });
    }
};
