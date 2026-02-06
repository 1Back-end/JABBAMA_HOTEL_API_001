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
            // 🔹 Créer la colonne d'abord
            $table->uuid('category_uuid')->nullable()->after('code');
        });

        // 🔹 Ajouter la contrainte FK dans une autre instruction
        Schema::table('menus_restaurants', function (Blueprint $table) {
            $table->foreign('category_uuid')
                ->references('uuid')
                ->on('menu_categories')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('menus_restaurants', function (Blueprint $table) {
            $table->dropForeign(['category_uuid']); // supprime la contrainte FK
            $table->dropColumn('category_uuid');   // supprime la colonne
        });
    }
};
