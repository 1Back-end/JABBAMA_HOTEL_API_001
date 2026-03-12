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
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {
            $table->boolean('is_new_items')->default(false);
            $table->boolean('is_last_items')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {
            $table->dropColumn('is_new_items');
            $table->dropColumn('is_last_items');
        });
    }
};
