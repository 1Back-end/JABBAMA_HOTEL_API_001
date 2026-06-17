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
        Schema::create('cash_receipt_types', function (Blueprint $table) {
            $table->uuid('uuid')->primary();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('is_active')->default(true);

            $table->string('code')->unique();

            $table->string('name')->unique();

            $table->boolean('is_linked_to_turnover')->default(false);

            $table->timestamps();

            $table->softDeletes();
            $table->index('is_active');
            $table->index('is_linked_to_turnover');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_receipt_types');
    }
};
