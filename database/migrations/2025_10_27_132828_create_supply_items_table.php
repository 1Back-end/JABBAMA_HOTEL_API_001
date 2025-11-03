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
        Schema::create('supply_items', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('supply_uuid');
            $table->uuid('product_uuid');
            $table->decimal('quantity_supplied', 15, 3);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('supply_uuid')->references('uuid')->on('supplies')->onDelete('cascade');
            $table->foreign('product_uuid')->references('uuid')->on('produits')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supply_items');
    }
};
