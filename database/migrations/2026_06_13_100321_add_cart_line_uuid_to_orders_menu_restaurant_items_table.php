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
            $table->uuid('cart_line_uuid')->nullable()->after('uuid');
            $table->index('cart_line_uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {
            $table->dropIndex(['cart_line_uuid']);
            $table->dropColumn('cart_line_uuid');
        });
    }
};
