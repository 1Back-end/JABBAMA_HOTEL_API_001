<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complement_virtual_temps', function (Blueprint $table) {
            if (Schema::hasColumn('complement_virtual_temps', 'is_new')) {
                $table->dropColumn('is_new');
            }
        });

        Schema::table('complement_virtual_temps', function (Blueprint $table) {
            $table->boolean('is_new')
                ->default(false)
                ->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('complement_virtual_temps', function (Blueprint $table) {
            if (Schema::hasColumn('complement_virtual_temps', 'is_new')) {
                $table->dropColumn('is_new');
            }
        });
    }
};
