<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_receipt_types', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name')->index();
        });

        Schema::table('restaurant_expense_types', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name')->index();
        });
    }

    public function down(): void
    {
        Schema::table('cash_receipt_types', function (Blueprint $table) {
            $table->dropColumn('slug');
        });

        Schema::table('restaurant_expense_types', function (Blueprint $table) {
            $table->dropColumn('slug');
        });
    }
};
