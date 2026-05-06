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
        Schema::create('last_status_drinks_menus_restaurants', function (Blueprint $table) {
            $table->uuid('uuid')->primary();

            // 🔥 ORDER
            $table->uuid('order_menu_restaurant_uuid');
            $table->foreign('order_menu_restaurant_uuid', 'lsdmr_order_fk')
                ->references('uuid')
                ->on('orders_menu_restaurants')
                ->cascadeOnDelete();

            // 🔥 PRODUCT (DRINK)
            $table->uuid('product_uuid');
            $table->foreign('product_uuid', 'lsdmr_product_fk')
                ->references('uuid')
                ->on('produits')
                ->cascadeOnDelete();

            // 🔥 DATA
            $table->string('type')->nullable();
            $table->string('last_status');

            // 🔥 USERS
            $table->foreignId('created_by')->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('last_status_drinks_menus_restaurants');
    }
};
