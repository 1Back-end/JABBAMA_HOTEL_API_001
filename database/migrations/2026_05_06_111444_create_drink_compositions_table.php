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
        Schema::create('drink_compositions', function (Blueprint $table) {
            $table->uuid('uuid')->primary();

            $table->uuid('drinks_restaurant_uuid');
            $table->uuid('product_uuid');

            $table->integer('quantity_used')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // FK relations
            $table->foreign('drinks_restaurant_uuid')
                ->references('uuid')
                ->on('restaurant_drink_configurations')
                ->onDelete('cascade');

            $table->foreign('product_uuid')
                ->references('uuid')
                ->on('produits')
                ->onDelete('cascade');

            // indexes (important performance)
            $table->index('drinks_restaurant_uuid');
            $table->index('product_uuid');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('drink_compositions');
    }
};
