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
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('reference')->unique();

            $table->uuid('warehouse_uuid')->nullable();
            $table->foreign('warehouse_uuid')
                ->references('uuid')
                ->on('warehouses')
                ->nullOnDelete();

            $table->string('notes')->nullable();
            $table->text('comment')->nullable();
            $table->integer('action')->default(1);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('validated_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->string('status')->default('pending');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
    }
};
