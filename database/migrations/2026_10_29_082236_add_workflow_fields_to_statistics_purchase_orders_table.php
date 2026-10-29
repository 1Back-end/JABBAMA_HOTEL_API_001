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
            // Validation
            if (!Schema::hasColumn('statistics_purchase_orders', 'validated_at')) {
                $table->timestamp('validated_at')->nullable();
            }
            if (!Schema::hasColumn('statistics_purchase_orders', 'validated_by')) {
                $table->foreignId('validated_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
            }

            // Rejet
            if (!Schema::hasColumn('statistics_purchase_orders', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable();
            }
            if (!Schema::hasColumn('statistics_purchase_orders', 'rejected_by')) {
                $table->foreignId('rejected_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
            }

            // Réception
            if (!Schema::hasColumn('statistics_purchase_orders', 'received_at')) {
                $table->timestamp('received_at')->nullable();
            }
            if (!Schema::hasColumn('statistics_purchase_orders', 'received_by')) {
                $table->foreignId('received_by')
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
            if (Schema::hasColumn('statistics_purchase_orders', 'validated_by')) {
                $table->dropForeign(['validated_by']);
                $table->dropColumn('validated_by');
            }
            if (Schema::hasColumn('statistics_purchase_orders', 'validated_at')) {
                $table->dropColumn('validated_at');
            }

            if (Schema::hasColumn('statistics_purchase_orders', 'rejected_by')) {
                $table->dropForeign(['rejected_by']);
                $table->dropColumn('rejected_by');
            }
            if (Schema::hasColumn('statistics_purchase_orders', 'rejected_at')) {
                $table->dropColumn('rejected_at');
            }

            if (Schema::hasColumn('statistics_purchase_orders', 'received_by')) {
                $table->dropForeign(['received_by']);
                $table->dropColumn('received_by');
            }
            if (Schema::hasColumn('statistics_purchase_orders', 'received_at')) {
                $table->dropColumn('received_at');
            }
        });
    }
};
