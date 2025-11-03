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
        Schema::table('categories', function (Blueprint $table) {
            $table->uuid('parent_uuid')->nullable()->after('uuid');

            $table->foreign('parent_uuid')
                ->references('uuid')
                ->on('categories')
                ->nullOnDelete(); // Si le parent est supprimé, l’enfant devient catégorie principale
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropForeign(['parent_uuid']);
            $table->dropColumn('parent_uuid');
        });
    }
};
