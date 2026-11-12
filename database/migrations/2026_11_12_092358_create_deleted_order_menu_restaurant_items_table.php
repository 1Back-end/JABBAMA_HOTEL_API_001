<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('deleted_order_menu_restaurant_items');

        Schema::create('deleted_order_menu_restaurant_items', function (Blueprint $table) {
            $table->uuid('uuid')->unique();

            $table->uuid('order_menu_restaurant_uuid');
            $table->uuid('menus_restaurant_uuid');

            $table->uuid('cart_line_uuid')->nullable();
            $table->decimal('quantity_ordered', 15, 2)->default(0);
            $table->decimal('unit_price', 15, 2)->default(0);

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('deleted_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreign('order_menu_restaurant_uuid', 'del_ord_menu_rest_uuid_fk')
                ->references('uuid')
                ->on('orders_menu_restaurants')
                ->cascadeOnDelete();

            $table->foreign('menus_restaurant_uuid', 'del_menu_rest_uuid_fk')
                ->references('uuid')
                ->on('menus_restaurants')
                ->cascadeOnDelete();

            $table->text('deletion_reason')->nullable();

            $table->timestamps();

            $table->index('order_menu_restaurant_uuid', 'del_ord_menu_uuid_idx');
            $table->index('menus_restaurant_uuid', 'del_menu_uuid_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deleted_order_menu_restaurant_items');
    }
};
