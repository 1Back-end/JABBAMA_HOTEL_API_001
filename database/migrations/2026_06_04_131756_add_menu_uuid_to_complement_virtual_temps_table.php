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
            $table->uuid('menu_uuid')->nullable()->after('reservation_uuid');

            $table->foreign('menu_uuid')
                ->references('uuid')
                ->on('menus_restaurants')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('complement_virtual_temps', function (Blueprint $table) {
            $table->dropForeign(['menu_uuid']);
            $table->dropColumn('menu_uuid');
        });
    }
};
