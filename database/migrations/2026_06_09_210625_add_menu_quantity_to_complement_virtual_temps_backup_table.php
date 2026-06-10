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
        Schema::table('complement_virtual_temps_backup', function (Blueprint $table) {
            $table->integer('menu_quantity')->default(0)->after('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('complement_virtual_temps_backup', function (Blueprint $table) {
            $table->dropColumn('menu_quantity');
        });
    }
};
