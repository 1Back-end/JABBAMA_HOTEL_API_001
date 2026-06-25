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
        Schema::table('free_clients_restaurants', function (Blueprint $table) {
            $table->integer('amount_allocated')
                ->default(0)
                ->after('full_name');

            $table->integer('amount_allocated_total')
                ->default(0)
                ->after('amount_allocated');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('free_clients_restaurants', function (Blueprint $table) {
            $table->dropColumn([
                'amount_allocated',
                'amount_allocated_total'
            ]);
        });
    }
};
