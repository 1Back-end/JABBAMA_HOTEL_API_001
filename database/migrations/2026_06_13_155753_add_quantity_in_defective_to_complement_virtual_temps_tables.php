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
        Schema::table('complement_virtual_temps', function (Blueprint $table) {
            $table->integer('quantity_in_defective')->default(0)->after('quantity_used');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('complement_virtual_temps', function (Blueprint $table) {
            $table->dropColumn('quantity_in_defective');
        });
    }
};
