<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Suppression de l'ancienne colonne si elle existe
        if (Schema::hasColumn('payment_lines', 'payment_regulation_uuid')) {

            Schema::table('payment_lines', function (Blueprint $table) {

                $foreignKeyExists = collect(DB::select("
                    SELECT CONSTRAINT_NAME
                    FROM information_schema.KEY_COLUMN_USAGE
                    WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'payment_lines'
                    AND COLUMN_NAME = 'payment_regulation_uuid'
                    AND REFERENCED_TABLE_NAME IS NOT NULL
                "))->isNotEmpty();


                if ($foreignKeyExists) {
                    $table->dropForeign(['payment_regulation_uuid']);
                }


                $table->dropColumn('payment_regulation_uuid');

            });
        }


        // Recréation propre de la colonne
        Schema::table('payment_lines', function (Blueprint $table) {

            $table->char('payment_regulation_uuid', 36)
                ->nullable()
                ->after('payment_uuid');

        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('payment_lines', 'payment_regulation_uuid')) {

            Schema::table('payment_lines', function (Blueprint $table) {

                $foreignKeyExists = collect(DB::select("
                    SELECT CONSTRAINT_NAME
                    FROM information_schema.KEY_COLUMN_USAGE
                    WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'payment_lines'
                    AND COLUMN_NAME = 'payment_regulation_uuid'
                    AND REFERENCED_TABLE_NAME IS NOT NULL
                "))->isNotEmpty();


                if ($foreignKeyExists) {
                    $table->dropForeign(['payment_regulation_uuid']);
                }


                $table->dropColumn('payment_regulation_uuid');

            });
        }
    }
};
