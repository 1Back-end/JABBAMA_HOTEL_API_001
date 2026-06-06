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
            $table->string('type_complement_menu')->nullable();

            $table->integer('quantity_for_type_complement_menu')->nullable();

            $table->integer('quantity_for_type_complement_boisson')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('menus_restaurants', function (Blueprint $table) {
            $table->dropColumn('type_complement_menu');
            $table->dropColumn('quantity_for_type_complement_menu');
            $table->dropColumn('quantity_for_type_complement_boisson');
        });
    }
};
