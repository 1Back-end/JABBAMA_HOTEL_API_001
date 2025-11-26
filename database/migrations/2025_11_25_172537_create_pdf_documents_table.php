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
        Schema::create('pdf_documents', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('name')->nullable();
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->string('filename')->nullable();
            $table->string('mimetype')->nullable();
            $table->string('extension')->nullable();
            $table->unsignedBigInteger('sequence')->unique();
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
        Schema::dropIfExists('pdf_documents');
    }
};
