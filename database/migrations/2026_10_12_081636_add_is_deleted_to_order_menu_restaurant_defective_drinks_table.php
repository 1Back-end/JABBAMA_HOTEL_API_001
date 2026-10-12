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
        Schema::table('order_menu_restaurant_defective_drinks', function (Blueprint $table) {
            if (!Schema::hasColumn('order_menu_restaurant_defective_drinks', 'is_deleted')) {
                $table->boolean('is_deleted')->default(false)->after('uuid');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_menu_restaurant_defective_drinks', function (Blueprint $table) {
            if (Schema::hasColumn('order_menu_restaurant_defective_drinks', 'is_deleted')) {
                $table->dropColumn('is_deleted');
            }
        });
    }
};
