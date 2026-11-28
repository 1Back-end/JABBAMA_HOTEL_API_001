<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasDeductionFlag = Schema::hasColumn('purchase_orders_notifications', 'is_deduction_stock');
        $hasDeductionUuid = Schema::hasColumn('purchase_orders_notifications', 'stocks_deductions_uuid');

        if (!$hasDeductionFlag || !$hasDeductionUuid) {
            Schema::table('purchase_orders_notifications', function (Blueprint $table) use ($hasDeductionFlag, $hasDeductionUuid) {
                if (!$hasDeductionFlag) {
                    $table->boolean('is_deduction_stock')->default(false);
                }

                if (!$hasDeductionUuid) {
                    $table->uuid('stocks_deductions_uuid')->nullable()->index();
                }
            });
        }
    }

    public function down(): void
    {
        $hasDeductionFlag = Schema::hasColumn('purchase_orders_notifications', 'is_deduction_stock');
        $hasDeductionUuid = Schema::hasColumn('purchase_orders_notifications', 'stocks_deductions_uuid');

        if ($hasDeductionUuid) {
            Schema::table('purchase_orders_notifications', function (Blueprint $table) {
                try {
                    $table->dropIndex(['stocks_deductions_uuid']);
                } catch (\Throwable $e) {
                }
            });
        }

        $columnsToDrop = [];
        if ($hasDeductionFlag) {
            $columnsToDrop[] = 'is_deduction_stock';
        }
        if ($hasDeductionUuid) {
            $columnsToDrop[] = 'stocks_deductions_uuid';
        }

        if (!empty($columnsToDrop)) {
            Schema::table('purchase_orders_notifications', function (Blueprint $table) use ($columnsToDrop) {
                $table->dropColumn($columnsToDrop);
            });
        }
    }
};
