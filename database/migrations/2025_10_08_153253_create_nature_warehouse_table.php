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
        Schema::create('nature_warehouse', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('warehouse_uuid');
            $table->uuid('nature_uuid');
            $table->timestamps();

            $table->foreign('warehouse_uuid')->references('uuid')->on('warehouses')->onDelete('cascade');
            $table->foreign('nature_uuid')->references('uuid')->on('nature_entrepots')->onDelete('cascade');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nature_warehouse');
    }
};
