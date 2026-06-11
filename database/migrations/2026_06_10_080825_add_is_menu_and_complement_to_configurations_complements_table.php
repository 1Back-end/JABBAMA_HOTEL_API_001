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
        Schema::table('configurations_complements', function (Blueprint $table) {
            $table->boolean('is_menu_and_complement')
                ->default(true)
                ->after('menus_complement_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('configurations_complements', function (Blueprint $table) {
            $table->dropColumn('is_menu_and_complement');
        });
    }
};
