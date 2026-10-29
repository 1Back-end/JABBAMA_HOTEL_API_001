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
            if (!Schema::hasColumn('statistics_purchase_orders', 'transfered_at')) {
                $table->timestamp('transfered_at')->nullable()->after('status');
            }
            if (!Schema::hasColumn('statistics_purchase_orders', 'transfered_by')) {
                $table->foreignId('transfered_by')
                    ->nullable()
                    ->after('transfered_at')
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
            if (Schema::hasColumn('statistics_purchase_orders', 'transfered_by')) {
                $table->dropForeign(['transfered_by']);
                $table->dropColumn('transfered_by');
            }
            if (Schema::hasColumn('statistics_purchase_orders', 'transfered_at')) {
                $table->dropColumn('transfered_at');
            }
        });
    }
};
