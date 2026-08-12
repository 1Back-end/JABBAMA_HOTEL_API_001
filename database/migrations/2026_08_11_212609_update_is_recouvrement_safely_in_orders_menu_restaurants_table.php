<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('orders_menu_restaurants', 'is_recouvrement')) {
            Schema::table('orders_menu_restaurants', function (Blueprint $table) {
                $table->boolean('is_recouvrement')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders_menu_restaurants', 'is_recouvrement')) {
            Schema::table('orders_menu_restaurants', function (Blueprint $table) {
                $table->dropColumn('is_recouvrement');
            });
        }
    }
};
