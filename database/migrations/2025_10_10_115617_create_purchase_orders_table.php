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
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('reference')->unique();
            $table->enum('type', ['external', 'internal'])->default('external');
            $table->enum('status', ['draft', 'open', 'closed', 'rejected', 'modified'])->default('draft');
            $table->uuid('warehouse_from')->nullable();
            $table->uuid('warehouse_to')->nullable();
            $table->uuid('supplier_uuid')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // 🔗 Relations
            $table->foreign('supplier_uuid')->references('uuid')->on('suppliers')->nullOnDelete();
            $table->foreign('warehouse_from')->references('uuid')->on('warehouses')->nullOnDelete();
            $table->foreign('warehouse_to')->references('uuid')->on('warehouses')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
