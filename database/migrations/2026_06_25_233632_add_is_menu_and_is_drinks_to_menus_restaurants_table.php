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
        Schema::table('menus_restaurants', function (Blueprint $table) {
            $table->boolean('is_menu')
                ->default(false)
                ->after('is_confectioned');

            $table->boolean('is_drinks')
                ->default(false)
                ->after('is_menu');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('menus_restaurants', function (Blueprint $table) {
            $table->dropColumn([
                'is_menu',
                'is_drinks',
            ]);
        });
    }
};
