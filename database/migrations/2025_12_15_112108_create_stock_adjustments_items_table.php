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
        Schema::create('stock_adjustments_items', function (Blueprint $table) {
            $table->uuid('uuid')->primary();

            $table->uuid('stock_adjustment_uuid');
            $table->foreign('stock_adjustment_uuid')
                ->references('uuid')
                ->on('stock_adjustments')
                ->cascadeOnDelete();

            $table->uuid('product_uuid')->nullable();
            $table->foreign('product_uuid')
                ->references('uuid')
                ->on('produits')
                ->nullOnDelete();

            $table->integer('quantity')->default(1);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments_items');
    }
};
