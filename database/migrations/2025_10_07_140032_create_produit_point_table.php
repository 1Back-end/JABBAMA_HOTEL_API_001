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
        Schema::create('produit_point', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->foreignUuid('produit_uuid')->references('uuid')->on('produits')->cascadeOnDelete();
            $table->foreignUuid('point_uuid')->references('uuid')->on('warehouses')->cascadeOnDelete();
            $table->integer('quantity')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('produit_point');
    }
};
