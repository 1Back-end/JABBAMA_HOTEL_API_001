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
        Schema::table('restaurant_expense_types_families', function (Blueprint $table) {
            $table->string('operation_type')->nullable()->after('indexation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurant_expense_types_families', function (Blueprint $table) {
            $table->dropColumn('operation_type');
        });
    }
};
