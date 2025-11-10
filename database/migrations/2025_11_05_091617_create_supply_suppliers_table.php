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
        Schema::create('supply_suppliers', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('supply_uuid');
            $table->uuid('supplier_uuid');
            $table->uuid('warehouse_uuid')->nullable();
            $table->text('notes')->nullable();

            $table->foreign('supply_uuid')->references('uuid')->on('supplies')->cascadeOnDelete();
            $table->foreign('supplier_uuid')->references('uuid')->on('suppliers')->cascadeOnDelete();
            $table->foreign('warehouse_uuid')->references('uuid')->on('warehouses')->nullOnDelete();

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
        Schema::dropIfExists('supply_suppliers');
    }
};
