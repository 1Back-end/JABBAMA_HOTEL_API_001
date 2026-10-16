<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_performance_criteria', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('label');
            $table->decimal('min_rate', 5, 2);
            $table->decimal('max_rate', 5, 2);
            $table->text('description_template');
            $table->string('badge_color')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_performance_criteria');
    }
};
