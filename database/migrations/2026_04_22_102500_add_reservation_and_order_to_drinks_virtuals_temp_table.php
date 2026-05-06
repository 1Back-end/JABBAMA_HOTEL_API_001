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
        Schema::table('drinks_virtuals_temp', function (Blueprint $table) {
            $table->uuid('reservation_uuid')->nullable()->after('uuid');
            $table->uuid('order_menu_restaurant_uuid')->nullable()->after('reservation_uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('drinks_virtuals_temp', function (Blueprint $table) {
            $table->dropColumn('reservation_uuid');
            $table->dropColumn('order_menu_restaurant_uuid');
        });
    }
};
