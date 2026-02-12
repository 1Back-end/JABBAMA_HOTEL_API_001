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
        Schema::table('menus_restaurants', function (Blueprint $table) {
            $table->dropColumn(['unit_price', 'special_price']);

            // 🔹 Nouvelle colonne JSON pour stocker plusieurs prix
            $table->json('unit_price')->after('is_active');      // ex: [1000, 2000, 3000]
            $table->json('special_price')->nullable()->after('unit_price'); // ex: [800, 1500]
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('menus_restaurants', function (Blueprint $table) {
            $table->dropColumn(['unit_price', 'special_price']);

            // 🔹 Retour aux colonnes classiques
            $table->decimal('unit_price', 10, 2)->after('is_active');
            $table->decimal('special_price', 10, 2)->nullable()->after('unit_price');
        });
    }
};
