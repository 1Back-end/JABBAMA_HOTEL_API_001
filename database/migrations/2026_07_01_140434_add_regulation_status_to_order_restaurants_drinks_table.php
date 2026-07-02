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
        Schema::table('order_restaurannts_drinks', function (Blueprint $table) {
            $table->string('regulation_status')->default('not_paid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_restaurants_drinks', function (Blueprint $table) {
            $table->dropColumn('regulation_status');
        });
    }
};
