<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders_decisional_notifications', function (Blueprint $table) {
            $table->boolean('is_stock_adjustment')
                ->default(false)
                ->after('stock_adjustments_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders_decisional_notifications', function (Blueprint $table) {
            $table->dropColumn('is_stock_adjustment');
        });
    }
};
