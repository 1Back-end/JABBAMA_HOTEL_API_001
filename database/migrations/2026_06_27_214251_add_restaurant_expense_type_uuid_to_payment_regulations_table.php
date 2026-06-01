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
        Schema::table('payment_regulations', function (Blueprint $table) {
            $table->uuid('restaurant_expense_type_uuid')
                ->nullable()
                ->after('cash_receipt_type_uuid');

            $table->foreign('restaurant_expense_type_uuid')
                ->references('uuid')
                ->on('restaurant_expense_types')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_regulations', function (Blueprint $table) {
            $table->dropForeign(['restaurant_expense_type_uuid']);
            $table->dropColumn('restaurant_expense_type_uuid');
        });
    }
};
