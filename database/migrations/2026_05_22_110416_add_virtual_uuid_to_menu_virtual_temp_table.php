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
        Schema::table('menu_virtuals_temp', function (Blueprint $table) {
            $table->uuid('virtual_uuid')
                ->nullable()
                ->index()
                ->after('product_uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('menu_virtuals_temp', function (Blueprint $table) {
            $table->dropColumn('virtual_uuid');
        });
    }
};
