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
        Schema::table('order_restaurannts_drinks', function (Blueprint $table) {
            if (!Schema::hasColumn('order_restaurannts_drinks', 'snapshot_additional_cost')) {
                $table->decimal('snapshot_additional_cost', 15, 2)->default(0);
            }
            if (!Schema::hasColumn('order_restaurannts_drinks', 'snapshot_composition_cost')) {
                $table->decimal('snapshot_composition_cost', 15, 2)->default(0);
            }
            if (!Schema::hasColumn('order_restaurannts_drinks', 'snapshot_complements_cost')) {
                $table->decimal('snapshot_complements_cost', 15, 2)->default(0);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_restaurannts_drinks', function (Blueprint $table) {
            $table->dropColumn([
                'snapshot_additional_cost',
                'snapshot_composition_cost',
                'snapshot_complements_cost',
            ]);
        });
    }
};
