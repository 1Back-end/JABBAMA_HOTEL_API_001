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
        Schema::table('category_tree', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('children'); // ou après un autre champ si tu veux
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('category_tree', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
