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
        Schema::table('restaurant_expense_types', function (Blueprint $table) {
            $table->boolean('is_linked_to_activity')
                ->default(false)
                ->after('name')
                ->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurant_expense_types', function (Blueprint $table) {
            $table->dropColumn('is_linked_to_activity');
        });
    }
};
