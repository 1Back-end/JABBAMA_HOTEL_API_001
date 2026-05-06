<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {

            // ✅ ajouter seulement si n'existe pas
            if (!Schema::hasColumn('orders_menu_restaurant_items', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders_menu_restaurant_items', function (Blueprint $table) {

            // ✅ supprimer si existe
            if (Schema::hasColumn('orders_menu_restaurant_items', 'rejected_at')) {
                $table->dropColumn('rejected_at');
            }
        });
    }
};
