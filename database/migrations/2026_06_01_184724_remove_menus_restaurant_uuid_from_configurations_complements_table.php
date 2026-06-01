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
            $table->dropForeign('configurations_complements_menus_restaurant_uuid_foreign');
            $table->dropColumn('menus_restaurant_uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('configurations_complements', function (Blueprint $table) {
            $table->uuid('menus_restaurant_uuid')->nullable();

            $table->foreign('menus_restaurant_uuid')
                ->references('uuid')
                ->on('menus_restaurants')
                ->nullOnDelete();
        });
    }
};
