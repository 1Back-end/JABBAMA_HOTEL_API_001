<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('purchase_orders_decisional_notifications')) {
            return;
        }

        Schema::table('purchase_orders_decisional_notifications', function (Blueprint $table) {
            if (!Schema::hasColumn('purchase_orders_decisional_notifications', 'stocks_deductions_uuid')) {
                $table->uuid('stocks_deductions_uuid')->nullable()->after('purchase_order_uuid');
                $table->index('stocks_deductions_uuid', 'pod_notif_stocks_deductions_uuid_idx');

                if (Schema::hasTable('stocks_deductions')) {
                    $table->foreign('stocks_deductions_uuid', 'pod_notif_stocks_deductions_uuid_fk')
                        ->references('uuid')
                        ->on('stocks_deductions')
                        ->nullOnDelete();
                }
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('purchase_orders_decisional_notifications')) {
            return;
        }

        Schema::table('purchase_orders_decisional_notifications', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_orders_decisional_notifications', 'stocks_deductions_uuid')) {
                try {
                    $table->dropForeign('pod_notif_stocks_deductions_uuid_fk');
                } catch (\Throwable $e) {
                    // Ignore si la contrainte n'existe pas
                }

                try {
                    $table->dropIndex('pod_notif_stocks_deductions_uuid_idx');
                } catch (\Throwable $e) {
                    // Ignore si l'index n'existe pas
                }

                $table->dropColumn('stocks_deductions_uuid');
            }
        });
    }
};
