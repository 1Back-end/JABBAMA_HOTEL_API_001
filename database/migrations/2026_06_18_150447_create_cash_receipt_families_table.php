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
        Schema::create('cash_receipt_families', function (Blueprint $table) {
            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('uuid')->primary();

            $table->string('name');
            $table->string('code')->unique();

            $table->uuid('cash_receipt_type_uuid');

            $table->softDeletes();

            $table->foreign('cash_receipt_type_uuid')
                ->references('uuid')
                ->on('cash_receipt_types')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_receipt_families');
    }
};
