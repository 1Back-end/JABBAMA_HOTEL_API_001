<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders_notifications', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_orders_notifications', 'purchase_order_uuid')) {
                $table->uuid('purchase_order_uuid')->nullable()->change();
            }

            if (!Schema::hasColumn('purchase_orders_notifications', 'passation_uuid')) {
                $table->uuid('passation_uuid')->nullable()->after('purchase_order_uuid');
            }

            if (!Schema::hasColumn('purchase_orders_notifications', 'stock_adjustments_uuid')) {
                $table->uuid('stock_adjustments_uuid')->nullable()->after('passation_uuid');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders_notifications', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_orders_notifications', 'stock_adjustments_uuid')) {
                $table->dropColumn('stock_adjustments_uuid');
            }

            if (Schema::hasColumn('purchase_orders_notifications', 'passation_uuid')) {
                $table->dropColumn('passation_uuid');
            }

            if (Schema::hasColumn('purchase_orders_notifications', 'purchase_order_uuid')) {
                $table->uuid('purchase_order_uuid')->nullable(false)->change();
            }
        });
    }
};
