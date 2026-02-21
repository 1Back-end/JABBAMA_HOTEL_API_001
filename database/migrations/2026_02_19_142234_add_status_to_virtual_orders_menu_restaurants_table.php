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
        Schema::table('virtual_orders_menu_restaurants', function (Blueprint $table) {
            $table->string('status')->default('pending')->after('quantity_reserved');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('virtual_orders_menu_restaurants', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
