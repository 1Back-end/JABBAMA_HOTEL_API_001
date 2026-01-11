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
        Schema::create('stocks_deductions', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('reference')->unique();

            $table->uuid('warehouse_uuid')->nullable()->constrained('warehouses')->nullOnDelete();

            $table->text('comment')->nullable();
            $table->string('action')->default('stocks_deductions');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();

            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason_of_cancel')->nullable();
            $table->timestamp('cancelled_at')->nullable();


            $table->string('status')->nullable()->default('draft');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stocks_deductions');
    }
};
