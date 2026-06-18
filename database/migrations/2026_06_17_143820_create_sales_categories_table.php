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
        Schema::create('sales_categories', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('name');
            $table->string('type');
            $table->time('start_time')->nullable();

            $table->time('end_time')->nullable();

            $table->boolean('is_active')->default(true);

            $table->string('code')->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index('name');
            $table->index('type');
            $table->index('is_active');
            $table->index('start_time');
            $table->index('end_time');
            $table->index(['type', 'is_active']);


        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_categories');
    }
};
