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
        Schema::create('complements_compositions', function (Blueprint $table) {
            $table->uuid('uuid')->primary();

            $table->uuid('commplements_restaurant_uuid');
            $table->uuid('warehouse_uuid');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('description')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // FK Configuration complément
            $table->foreign('commplements_restaurant_uuid')
                ->references('uuid')
                ->on('configurations_complements')
                ->cascadeOnDelete();

            // FK Entrepôt
            $table->foreign('warehouse_uuid')
                ->references('uuid')
                ->on('warehouses')
                ->cascadeOnDelete();

            // Index
            $table->index('commplements_restaurant_uuid');
            $table->index('warehouse_uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('complements_compositions');
    }
};
