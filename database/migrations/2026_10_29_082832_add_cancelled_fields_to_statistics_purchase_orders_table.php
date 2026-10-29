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
        Schema::table('statistics_purchase_orders', function (Blueprint $table) {
            // Approbation / Approvisionnement
            if (!Schema::hasColumn('statistics_purchase_orders', 'approved_at')) {
                $table->timestamp('approved_at')->nullable();
            }
            if (!Schema::hasColumn('statistics_purchase_orders', 'approved_by')) {
                $table->foreignId('approved_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
            }

            // Annulation
            if (!Schema::hasColumn('statistics_purchase_orders', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable();
            }
            if (!Schema::hasColumn('statistics_purchase_orders', 'cancelled_by')) {
                $table->foreignId('cancelled_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('statistics_purchase_orders', function (Blueprint $table) {
            if (Schema::hasColumn('statistics_purchase_orders', 'approved_by')) {
                $table->dropForeign(['approved_by']);
                $table->dropColumn('approved_by');
            }
            if (Schema::hasColumn('statistics_purchase_orders', 'approved_at')) {
                $table->dropColumn('approved_at');
            }

            if (Schema::hasColumn('statistics_purchase_orders', 'cancelled_by')) {
                $table->dropForeign(['cancelled_by']);
                $table->dropColumn('cancelled_by');
            }
            if (Schema::hasColumn('statistics_purchase_orders', 'cancelled_at')) {
                $table->dropColumn('cancelled_at');
            }
        });
    }
};
