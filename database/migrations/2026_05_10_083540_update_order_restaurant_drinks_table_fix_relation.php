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
        Schema::table('order_restaurannts_drinks', function (Blueprint $table) {

            // 1. supprimer la FOREIGN KEY d'abord
            $table->dropForeign('order_restaurannts_drinks_product_uuid_foreign');

            // 2. ajouter la nouvelle colonne
            $table->uuid('drink_restaurant_uuid')
                ->nullable()
                ->after('order_menu_restaurant_uuid');

            // 3. supprimer la colonne
            $table->dropColumn('product_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('order_restaurannts_drinks', function (Blueprint $table) {

            // remettre colonne
            $table->uuid('product_uuid')->nullable();

            // recréer FK proprement
            $table->foreign('product_uuid')
                ->references('uuid')
                ->on('produits')
                ->onDelete('cascade');

            $table->dropColumn('drink_restaurant_uuid');
        });
    }
};
