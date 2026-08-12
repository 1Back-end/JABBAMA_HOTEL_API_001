<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('other_cash_ins', function (Blueprint $table) {
            $table->uuid('cash_receipt_family_uuid')->nullable()->after('uuid');
            $table->json('family_hierarchy_uuids')->nullable()->after('cash_receipt_family_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('other_cash_ins', function (Blueprint $table) {
            $table->dropColumn(['cash_receipt_family_uuid', 'family_hierarchy_uuids']);
        });
    }
};
