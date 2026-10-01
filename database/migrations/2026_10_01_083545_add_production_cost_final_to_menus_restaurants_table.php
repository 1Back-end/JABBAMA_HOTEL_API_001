<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menus_restaurants', function (Blueprint $table) {
            $table->decimal('production_cost_final', 15, 2)->default(0)->after('production_cost');
        });
    }

    public function down(): void
    {
        Schema::table('menus_restaurants', function (Blueprint $table) {
            $table->dropColumn('production_cost_final');
        });
    }
};
