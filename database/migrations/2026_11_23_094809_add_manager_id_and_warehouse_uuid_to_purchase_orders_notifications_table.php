<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders_notifications', function (Blueprint $table) {
            if (!Schema::hasColumn('purchase_orders_notifications', 'manager_id')) {
                $table->unsignedBigInteger('manager_id')->nullable()->after('user_id');
                $table->foreign('manager_id')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
                $table->index('manager_id');
            }

            if (!Schema::hasColumn('purchase_orders_notifications', 'warehouse_uuid')) {
                $table->uuid('warehouse_uuid')->nullable()->after('manager_id');
                $table->foreign('warehouse_uuid')
                    ->references('uuid')
                    ->on('warehouses')
                    ->nullOnDelete();
                $table->index('warehouse_uuid');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders_notifications', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_orders_notifications', 'manager_id')) {
                $table->dropForeign(['manager_id']);
                $table->dropIndex(['manager_id']);
                $table->dropColumn('manager_id');
            }

            if (Schema::hasColumn('purchase_orders_notifications', 'warehouse_uuid')) {
                $table->dropForeign(['warehouse_uuid']);
                $table->dropIndex(['warehouse_uuid']);
                $table->dropColumn('warehouse_uuid');
            }
        });
    }
};
