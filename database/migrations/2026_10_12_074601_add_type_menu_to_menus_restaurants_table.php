<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menus_restaurants', function (Blueprint $table) {
            if (!Schema::hasColumn('menus_restaurants', 'type_menu')) {
                $table->string('type_menu')->default('MENU')->after('uuid');
            }
        });
    }

    public function down(): void
    {
        Schema::table('menus_restaurants', function (Blueprint $table) {
            if (Schema::hasColumn('menus_restaurants', 'type_menu')) {
                $table->dropColumn('type_menu');
            }
        });
    }
};
