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
        Schema::create('menu_order_items_buffer', function (Blueprint $table) {
            $table->uuid('uuid')->primary();

            $table->uuid('menu_order_uuid');
            $table->foreign('menu_order_uuid')->references('uuid')->on('menu_orders')->onDelete('cascade');

            $table->uuid('product_uuid');
            $table->foreign('product_uuid')->references('uuid')->on('produits')->onDelete('cascade');

            $table->integer('quantity_used')->default(0);

            $table->integer('stock_initial')->default(0);
            $table->uuid('warehouse_uuid')->nullable();

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
        Schema::dropIfExists('menu_order_items_buffer');
    }
};
