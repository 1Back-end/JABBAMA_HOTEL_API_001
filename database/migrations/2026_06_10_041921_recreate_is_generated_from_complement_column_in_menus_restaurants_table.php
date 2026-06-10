<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('menus_restaurants', 'is_generated_from_complement')) {
            Schema::table('menus_restaurants', function (Blueprint $table) {
                $table->dropColumn('is_generated_from_complement');
            });
        }

        Schema::table('menus_restaurants', function (Blueprint $table) {
            $table->boolean('is_generated_from_complement')
                ->default(false)
                ->after('is_active');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('menus_restaurants', 'is_generated_from_complement')) {
            Schema::table('menus_restaurants', function (Blueprint $table) {
                $table->dropColumn('is_generated_from_complement');
            });
        }
    }
};
