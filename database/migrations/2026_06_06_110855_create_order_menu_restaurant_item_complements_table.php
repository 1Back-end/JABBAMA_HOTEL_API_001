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
        Schema::create('orders_items_complements', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->uuid('order_menu_restaurant_item_uuid');
            $table->uuid('configuration_complement_uuid');

            $table->foreign('order_menu_restaurant_item_uuid')
                ->references('uuid')
                ->on('orders_menu_restaurant_items')
                ->cascadeOnDelete();

            $table->foreign('configuration_complement_uuid')
                ->references('uuid')
                ->on('configurations_complements')
                ->cascadeOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders_items_complements');
    }
};
