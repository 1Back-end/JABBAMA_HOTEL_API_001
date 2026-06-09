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
        Schema::table('configurations_complements', function (Blueprint $table) {

            if (!Schema::hasColumn('configurations_complements', 'is_sellable_directly')) {
                $table->boolean('is_sellable_directly')
                    ->default(false)
                    ->after('menus_complement_type');
            }

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('configurations_complements', function (Blueprint $table) {
            if (Schema::hasColumn('configurations_complements', 'is_sellable_directly')) {
                $table->dropColumn('is_sellable_directly');
            }
        });
    }
};
