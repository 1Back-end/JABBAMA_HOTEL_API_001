<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('passations_notifications', 'stock_adjustments_uuid')) {
            Schema::table('passations_notifications', function (Blueprint $table) {
                $col = $table->uuid('stock_adjustments_uuid')->nullable()->index();

                if (Schema::hasColumn('passations_notifications', 'passation_uuid')) {
                    $col->after('passation_uuid');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('passations_notifications', 'stock_adjustments_uuid')) {
            Schema::table('passations_notifications', function (Blueprint $table) {
                $table->dropIndex(['stock_adjustments_uuid']);
                $table->dropColumn('stock_adjustments_uuid');
            });
        }
    }
};
