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
            $table->uuid('cart_line_uuid')
                ->nullable()
                ->after('reservation_uuid')
                ->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('menu_virtuals_temp', function (Blueprint $table) {
            $table->dropColumn('cart_line_uuid');
        });
    }
};
