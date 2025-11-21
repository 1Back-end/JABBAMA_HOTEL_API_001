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
        Schema::create('passations', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('agent_from_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('agent_to_id')->nullable()->constrained('users')->onDelete('set null');

            $table->uuid('warehouse_uuid')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->string('status')->nullable()->default('pending');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('passations');
    }
};
