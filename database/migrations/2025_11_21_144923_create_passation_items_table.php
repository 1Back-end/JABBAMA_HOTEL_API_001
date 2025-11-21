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
        Schema::create('passation_items', function (Blueprint $table) {
            $table->uuid('uuid')->primary();

            $table->uuid('passation_uuid');
            $table->foreign('passation_uuid')->references('uuid')->on('passations')->onDelete('cascade');

            $table->uuid('product_uuid');
            $table->foreign('product_uuid')->references('uuid')->on('produits')->onDelete('cascade');

            $table->integer('quantity_sent')->default(0);
            $table->integer('quantity_counted')->nullable()->default(0);
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
        Schema::dropIfExists('passation_items');
    }
};
