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
        Schema::create('drinks_virtuals_temp', function (Blueprint $table) {
            $table->uuid('uuid')->primary()->unique();

            $table->string('code')->unique();
            $table->integer('quantity')->default(0);

            $table->uuid('product_uuid');
            $table->foreign('product_uuid')->references('uuid')->on('produits')->onDelete('cascade');

            $table->decimal('quantity_used', 10, 2)->default(0);

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
        Schema::dropIfExists('drinks_virtuals_temp');
    }
};
