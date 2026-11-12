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
        Schema::dropIfExists('deleted_order_restaurant_drinks');

        Schema::create('deleted_order_restaurant_drinks', function (Blueprint $table) {

            $table->uuid('uuid')->unique();

            $table->uuid('order_menu_restaurant_uuid');
            $table->uuid('drink_restaurant_uuid');

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

            $table->foreign('order_menu_restaurant_uuid', 'del_ord_drink_ord_uuid_fk')
                ->references('uuid')
                ->on('orders_menu_restaurants')
                ->cascadeOnDelete();

            $table->foreign('drink_restaurant_uuid', 'del_ord_drink_uuid_fk')
                ->references('uuid')
                ->on('restaurant_drink_configurations')
                ->cascadeOnDelete();

            $table->text('deletion_reason')->nullable();

            $table->timestamps();

            $table->index('order_menu_restaurant_uuid', 'del_ord_drink_ord_idx');
            $table->index('drink_restaurant_uuid', 'del_ord_drink_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deleted_order_restaurant_drinks');
    }
};
