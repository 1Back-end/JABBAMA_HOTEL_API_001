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
        Schema::table('restaurant_drink_configurations', function (Blueprint $table) {
            $table->string('image_file')->nullable()->after('drink_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurant_drink_configurations', function (Blueprint $table) {
            $table->dropColumn('image_file');
        });
    }
};
