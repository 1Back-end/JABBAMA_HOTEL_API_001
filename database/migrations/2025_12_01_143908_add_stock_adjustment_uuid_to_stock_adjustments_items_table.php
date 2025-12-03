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
        Schema::table('stock_adjustments_items', function (Blueprint $table) {
            $table->uuid('stock_adjustment_uuid')->after('uuid')->nullable();

            // Clé étrangère vers stock_adjustments
            $table->foreign('stock_adjustment_uuid')
                ->references('uuid')
                ->on('stock_adjustments')
                ->onDelete('cascade'); // supprime les items si ajustement supprimé
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_adjustments_items', function (Blueprint $table) {
            $table->dropForeign(['stock_adjustment_uuid']);
            $table->dropColumn('stock_adjustment_uuid');
        });
    }
};
