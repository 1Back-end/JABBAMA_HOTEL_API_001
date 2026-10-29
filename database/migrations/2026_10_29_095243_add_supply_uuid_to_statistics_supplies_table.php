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
        Schema::table('statistics_supplies', function (Blueprint $table) {
            if (!Schema::hasColumn('statistics_supplies', 'supply_uuid')) {
                $table->uuid('supply_uuid')->nullable()->after('purchase_order_uuid');
                $table->foreign('supply_uuid')
                    ->references('uuid')
                    ->on('supplies')
                    ->onDelete('cascade');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('statistics_supplies', function (Blueprint $table) {
            if (Schema::hasColumn('statistics_supplies', 'supply_uuid')) {
                $table->dropForeign(['supply_uuid']);
                $table->dropColumn('supply_uuid');
            }
        });
    }
};
