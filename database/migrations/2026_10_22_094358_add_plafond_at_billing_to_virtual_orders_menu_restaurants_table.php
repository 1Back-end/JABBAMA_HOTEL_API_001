<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('virtual_orders_menu_restaurants', function (Blueprint $table) {
            $table->decimal('plafond_at_billing', 10, 2)->nullable()->after('stock_at_billing');
        });
    }

    public function down(): void
    {
        Schema::table('virtual_orders_menu_restaurants', function (Blueprint $table) {
            $table->dropColumn('plafond_at_billing');
        });
    }
};
