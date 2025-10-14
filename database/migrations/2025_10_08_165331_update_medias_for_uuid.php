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
        Schema::table('medias', function (Blueprint $table) {
            $table->dropColumn(['mediable_id', 'mediable_type']);

            // Ajouter les colonnes pour UUID
            $table->string('mediable_id')->after('uuid');
            $table->string('mediable_type')->after('mediable_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medias', function (Blueprint $table) {
            $table->dropColumn(['mediable_id', 'mediable_type']);
            $table->morphs('mediable');
        });
    }
};
