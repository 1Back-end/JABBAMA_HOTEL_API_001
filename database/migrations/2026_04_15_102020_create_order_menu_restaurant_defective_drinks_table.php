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
        Schema::create('order_menu_restaurant_defective_drinks', function (Blueprint $table) {
            $table->uuid('uuid')->primary();

            // 🔥 relation order item (drinks liés à la commande)
            $table->uuid('order_menu_restaurant_item_uuid');
            $table->foreign('order_menu_restaurant_item_uuid', 'omrddi_item_fk')
                ->references('uuid')
                ->on('orders_menu_restaurant_items')
                ->cascadeOnDelete();

            // 🔥 relation order
            $table->uuid('product_uuid');
            $table->foreign('product_uuid', 'omrdd_product_fk')
                ->references('uuid')
                ->on('produits')
                ->cascadeOnDelete();

            $table->string('status')->default('defective');
            $table->integer('quantity')->default(0);
            $table->text('reason')->nullable();
            $table->string('type')->nullable();

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
        Schema::dropIfExists('order_menu_restaurant_defective_drinks');
    }
};
