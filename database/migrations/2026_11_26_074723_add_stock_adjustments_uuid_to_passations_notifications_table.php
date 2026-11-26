<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('passations_notifications', function (Blueprint $table) {
            $table->uuid('stock_adjustments_uuid')->nullable()->index()->after('passation_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('passations_notifications', function (Blueprint $table) {
            $table->dropIndex(['stock_adjustments_uuid']);
            $table->dropColumn('stock_adjustments_uuid');
        });
    }
};
