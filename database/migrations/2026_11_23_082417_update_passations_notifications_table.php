<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('passations_notifications', function (Blueprint $table) {
            if (Schema::hasColumn('passations_notifications', 'target')) {
                $table->dropColumn('target');
            }

            if (!Schema::hasColumn('passations_notifications', 'warehouse_uuid')) {
                $table->uuid('warehouse_uuid')->nullable()->after('passation_uuid');
                $table->index('warehouse_uuid');
            }

            if (!Schema::hasColumn('passations_notifications', 'manager_id')) {
                $table->unsignedBigInteger('manager_id')->nullable()->after('user_id');
                $table->index('manager_id');
            }

        });
    }

    public function down(): void
    {
        Schema::table('passations_notifications', function (Blueprint $table) {
            if (!Schema::hasColumn('passations_notifications', 'target')) {
                $table->string('target')->nullable()->after('message');
            }

            if (Schema::hasColumn('passations_notifications', 'warehouse_uuid')) {
                $table->dropIndex(['warehouse_uuid']);
                $table->dropColumn('warehouse_uuid');
            }

            if (Schema::hasColumn('passations_notifications', 'manager_id')) {
                $table->dropIndex(['manager_id']);
                $table->dropColumn('manager_id');
            }
        });
    }
};
