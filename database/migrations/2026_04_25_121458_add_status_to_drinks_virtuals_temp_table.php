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
        Schema::table('drinks_virtuals_temp', function (Blueprint $table) {
            $table->string('status')->default('pending')->after('quantity_used');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('drinks_virtuals_temp', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
