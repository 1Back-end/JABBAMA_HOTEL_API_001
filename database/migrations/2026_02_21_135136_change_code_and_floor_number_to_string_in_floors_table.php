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
        Schema::table('floors', function (Blueprint $table) {
            // 🔹 supprimer l'unique existant
            $table->dropUnique('floors_code_unique');
        });

        Schema::table('floors', function (Blueprint $table) {
            // 🔹 changer le type
            $table->string('code')->change();
        });

        Schema::table('floors', function (Blueprint $table) {
            // 🔹 recréer l'unique
            $table->unique('code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('floors', function (Blueprint $table) {
            $table->dropUnique('floors_code_unique');
        });

        Schema::table('floors', function (Blueprint $table) {
            $table->integer('code')->change();
        });

        Schema::table('floors', function (Blueprint $table) {
            $table->unique('code');
        });

    }
};
