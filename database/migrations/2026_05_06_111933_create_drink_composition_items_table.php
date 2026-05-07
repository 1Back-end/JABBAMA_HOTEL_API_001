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
        Schema::create('drink_composition_items', function (Blueprint $table) {
            $table->uuid('uuid')->primary();

            // lien vers la composition principale
            $table->uuid('drink_composition_uuid');

            // article / produit utilisé
            $table->uuid('product_uuid');

            $table->decimal('quantity_used', 10, 2)->default(0);

            // unité (ml, g, etc.)
            $table->uuid('unit_uuid')->nullable();

            $table->boolean('is_optional')->default(false);

            // audit
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // FK
            $table->foreign('drink_composition_uuid')
                ->references('uuid')
                ->on('drink_compositions')
                ->onDelete('cascade');

            $table->foreign('product_uuid')
                ->references('uuid')
                ->on('produits')
                ->onDelete('cascade');

            $table->index('drink_composition_uuid');
            $table->index('product_uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('drink_composition_items');
    }
};
