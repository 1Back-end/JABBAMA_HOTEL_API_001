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
        Schema::create('order_restaurannts_drinks', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('order_menu_restaurant_uuid')->nullable();
            $table->foreign('order_menu_restaurant_uuid')->references('uuid')->on('orders_menu_restaurants')->onDelete('cascade');

            $table->uuid('product_uuid')->nullable();
            $table->foreign('product_uuid')->references('uuid')->on('produits')->onDelete('restrict');
            $table->integer('quantity')->default(1)->nullable();
            $table->integer('unit_price')->default(0)->nullable();
            $table->integer('total_price')->default(0)->nullable();
            $table->string('status')->default('not_delivered')->nullable();

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
        Schema::dropIfExists('order_restaurannts_drinks');
    }
};
