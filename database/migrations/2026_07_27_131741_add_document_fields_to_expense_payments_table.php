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
        Schema::table('expense_payments', function (Blueprint $table) {
            $table->string('category_document')->nullable()->after('uuid');
            $table->string('type_document')->nullable()->after('category_document');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expense_payments', function (Blueprint $table) {
            $table->dropColumn(['category_document', 'type_document']);
        });
    }
};
