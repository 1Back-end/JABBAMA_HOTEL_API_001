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
        Schema::table('stocks_deductions_items', function (Blueprint $table) {

            $table->uuid('stocks_deduction_uuid')->after('uuid');

            // Ajouter la clé étrangère
            $table->foreign('stocks_deduction_uuid')
                ->references('uuid')
                ->on('stocks_deductions')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stocks_deductions_items', function (Blueprint $table) {
            $table->dropForeign(['stocks_deduction_uuid']);
            $table->dropColumn('stocks_deduction_uuid');
        });
    }
};
