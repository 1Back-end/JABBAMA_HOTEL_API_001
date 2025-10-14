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
        Schema::create('produit_sub_category', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->foreignUuid('produit_uuid')->references('uuid')->on('produits')->cascadeOnDelete();
            $table->foreignUuid('sub_category_uuid')->references('uuid')->on('sub_categories')->cascadeOnDelete();
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
        Schema::dropIfExists('produit_sub_category');
    }
};
