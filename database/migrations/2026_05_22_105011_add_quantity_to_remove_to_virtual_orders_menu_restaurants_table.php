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
            $table->unsignedInteger('quantity_to_remove')
                ->default(0)
                ->after('quantity_in_defective');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('virtual_orders_menu_restaurants', function (Blueprint $table) {
            $table->dropColumn('quantity_to_remove');
        });
    }
};
