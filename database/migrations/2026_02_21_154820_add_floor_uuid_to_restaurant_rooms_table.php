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
        Schema::table('restaurant_rooms', function (Blueprint $table) {
            $table->uuid('floor_uuid')->nullable()->after('code');

            $table->foreign('floor_uuid')->references('uuid')->on('floors')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurant_rooms', function (Blueprint $table) {
            $table->dropForeign(['floor_uuid']);
            $table->dropColumn('floor_uuid');
        });
    }
};
