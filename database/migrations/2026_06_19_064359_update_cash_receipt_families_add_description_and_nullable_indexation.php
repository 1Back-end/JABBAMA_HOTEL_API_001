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
        Schema::table('cash_receipt_families', function (Blueprint $table) {
            $table->string('indexation')
                ->nullable()
                ->change();

            // ajout description
            $table->text('description')
                ->nullable()
                ->after('indexation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cash_receipt_families', function (Blueprint $table) {
            $table->dropColumn('description');

            // rollback indexation (optionnel)
            $table->string('indexation')
                ->default('restaurant')
                ->change();
        });
    }
};
