<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exécuter la migration.
     */
    public function up(): void
    {
        Schema::table('payment_lines', function (Blueprint $table) {
            $table->string('type')->nullable();
        });
    }

    /**
     * Annuler la migration.
     */
    public function down(): void
    {
        Schema::table('payment_lines', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
