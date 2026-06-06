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
            $table->boolean('have_complements')->default(false)->after('description');
            $table->boolean('have_drinks')->default(false)->after('has_complements');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('menus_restaurants', function (Blueprint $table) {
            $table->dropColumn('has_complements');
            $table->dropColumn('has_drinks');
        });
    }
};
