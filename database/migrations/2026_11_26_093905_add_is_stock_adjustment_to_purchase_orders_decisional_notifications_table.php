<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('purchase_orders_decisional_notifications', 'is_stock_adjustment')) {
            Schema::table('purchase_orders_decisional_notifications', function (Blueprint $table) {
                $col = $table->boolean('is_stock_adjustment')->default(false);

                if (Schema::hasColumn('purchase_orders_decisional_notifications', 'stock_adjustments_uuid')) {
                    $col->after('stock_adjustments_uuid');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('purchase_orders_decisional_notifications', 'is_stock_adjustment')) {
            Schema::table('purchase_orders_decisional_notifications', function (Blueprint $table) {
                $table->dropColumn('is_stock_adjustment');
            });
        }
    }
};
