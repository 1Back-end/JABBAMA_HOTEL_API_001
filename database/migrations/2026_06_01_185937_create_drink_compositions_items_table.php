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
        Schema::create('complements_compositions_items', function (Blueprint $table) {
            $table->uuid('uuid')->primary();

            $table->uuid('complement_uuid');
            $table->foreign('complement_uuid')
                ->references('uuid')
                ->on('complements_compositions')
                ->cascadeOnDelete();

            $table->uuid('product_uuid');
            $table->foreign('product_uuid')
                ->references('uuid')
                ->on('produits')
                ->cascadeOnDelete();


            $table->integer('quantity_used')->default(0);

            $table->boolean('is_optional')->default(false);

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();


            $table->softDeletes();

            $table->index('complement_uuid');
            $table->index('product_uuid');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('complements_compositions_items');
    }
};
