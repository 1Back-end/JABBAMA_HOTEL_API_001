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
        Schema::table('passations', function (Blueprint $table) {
            $table->integer('difference')->nullable()->default(0);
            $table->integer('quantity_sent')->nullable()->default(0);
            $table->integer('quantity_counted')->nullable()->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('passations', function (Blueprint $table) {
            $table->dropColumn('difference');
            $table->dropColumn('quantity_sent');
            $table->dropColumn('quantity_counted');
        });
    }
};
