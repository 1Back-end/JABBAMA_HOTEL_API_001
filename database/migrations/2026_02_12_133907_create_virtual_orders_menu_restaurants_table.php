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
        Schema::create('virtual_orders_menu_restaurants', function (Blueprint $table) {
            $table->uuid('uuid')->primary();

            // 🔗 Commande principale
            $table->uuid('orders_menu_restaurant_uuid');
            $table->foreign('orders_menu_restaurant_uuid','vor_orders_menu_fk')->references('uuid')->on('orders_menu_restaurants')->cascadeOnDelete();

            // 🔗 Article du menu
            $table->uuid('product_uuid');
            $table->foreign('product_uuid','vor_product_fk')->references('uuid')->on('produits')->cascadeOnDelete();

            // 🔹 Quantité réservée dans la table virtuelle
            $table->integer('quantity_reserved')->default(0);

            // 🕒 Audit
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('virtual_orders_menu_restaurants');
    }
};
