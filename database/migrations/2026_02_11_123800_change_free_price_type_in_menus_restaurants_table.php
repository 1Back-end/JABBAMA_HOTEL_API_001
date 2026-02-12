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
            $table->integer('free_price')->default(0)->change()->comment('Prix gratuit pour certains clients');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('menus_restaurants', function (Blueprint $table) {
            $table->decimal('free_price', 8, 2)->default(0)->change()->comment('Prix gratuit pour certains clients');
        });
    }
};
