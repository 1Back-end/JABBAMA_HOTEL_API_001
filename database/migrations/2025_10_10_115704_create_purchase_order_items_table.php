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
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('purchase_order_uuid');
            $table->uuid('product_uuid');
            $table->decimal('quantity', 15, 3);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // 🔗 Relations
            $table->foreign('purchase_order_uuid')->references('uuid')->on('purchase_orders')->cascadeOnDelete();
            $table->foreign('product_uuid')->references('uuid')->on('produits')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};
