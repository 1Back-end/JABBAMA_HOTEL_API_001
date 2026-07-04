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
        Schema::dropIfExists('client_allocations');
        Schema::create('client_allocations', function (Blueprint $table) {
            $table->uuid('uuid')->primary();

            $table->string('source_type');
            $table->uuid('source_uuid')->unique();

            $table->string('client_name')->nullable();
            $table->decimal('amount_allocated', 15, 2)->default(0);
            $table->decimal('amount_allocated_total', 15, 2)->default(0);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_allocations');
    }
};
