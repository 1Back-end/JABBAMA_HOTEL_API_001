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
        Schema::table('sub_categories', function (Blueprint $table) {
            $table->dropUnique('sub_categories_name_unique');

            // Ajouter une nouvelle contrainte unique sur category_uuid + parent_uuid + name
            $table->unique(['category_uuid', 'parent_uuid', 'name'], 'sub_categories_unique_per_parent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sub_categories', function (Blueprint $table) {
            $table->dropUnique('sub_categories_unique_per_parent');
            $table->unique('name', 'sub_categories_name_unique');
        });
    }
};
