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
        Schema::create('orders_menu_item_statuses_for_drinks', function (Blueprint $table) {
            $table->uuid('uuid')->primary();

            // 🔥 relation order item
            $table->uuid('order_menu_restaurant_item_uuid');
            $table->foreign('order_menu_restaurant_item_uuid', 'omisfd_item_fk')
                ->references('uuid')
                ->on('orders_menu_restaurant_items')
                ->cascadeOnDelete();

            // 🔥 relation product (drink)
            $table->uuid('product_uuid');
            $table->foreign('product_uuid', 'omisfd_product_fk')
                ->references('uuid')
                ->on('produits')
                ->cascadeOnDelete();

            // 🔥 data
            $table->string('status')->default('transferred');
            $table->integer('quantity')->default(0);
            $table->integer('quantity_exactly')->default(0);
            $table->integer('quantity_accumulated')->default(0);

            // 🔥 users
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
        Schema::dropIfExists('orders_menu_item_statuses_for_drinks');
    }
};
