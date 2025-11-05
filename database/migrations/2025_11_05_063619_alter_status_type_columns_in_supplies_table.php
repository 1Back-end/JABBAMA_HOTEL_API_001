<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplies', function (Blueprint $table) {
            // Ajouter "type" en string si elle n'existe pas
            if (!Schema::hasColumn('supplies', 'type')) {
                $table->string('type')->nullable()->after('reference');
            }

            // Modifier "status" en string libre
            if (Schema::hasColumn('supplies', 'status')) {
                $table->string('status')->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('supplies', function (Blueprint $table) {
            if (Schema::hasColumn('supplies', 'type')) {
                $table->dropColumn('type');
            }

            if (Schema::hasColumn('supplies', 'status')) {
                $table->string('status')->nullable()->change();
            }
        });
    }
};
