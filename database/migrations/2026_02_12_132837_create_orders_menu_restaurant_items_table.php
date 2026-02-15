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
        Schema::create('orders_menu_restaurant_items', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('order_menu_restaurant_uuid');
            $table->foreign('order_menu_restaurant_uuid')->references('uuid')->on('orders_menu_restaurants')->onDelete('cascade');

            $table->uuid('menus_restaurant_uuid');
            $table->foreign('menus_restaurant_uuid')->references('uuid')->on('menus_restaurants')->onDelete('cascade');

            $table->integer('quantity')->nullable()->default(1);

            $table->integer('unit_price')->nullable()->default(0);
            $table->integer('total_price')->nullable()->default(0);

            $table->boolean('is_free')->nullable()->default(false);

            $table->string('description')->nullable();

            $table->string('status')->nullable()->default('pending');

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
        Schema::dropIfExists('orders_menu_restaurant_items');
    }
};
