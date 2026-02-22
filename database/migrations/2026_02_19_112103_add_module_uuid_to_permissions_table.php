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
        Schema::table('permissions', function (Blueprint $table) {

            $table->uuid('module_uuid')
                ->nullable()
                ->after('category_id')
                ->comment('Lien vers le module auquel appartient la permission');


            $table->foreign('module_uuid')
                ->references('uuid')
                ->on('modules_applications')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->dropColumn('module_uuid');
        });
    }
};
