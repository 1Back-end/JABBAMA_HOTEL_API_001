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
        Schema::table('supplies', function (Blueprint $table) {
            // Changer 'type' en string au lieu d'enum
            $table->string('type')->default('internal')->after('reference')->change();

            // Changer 'status' en string au lieu d'enum
            $table->string('status')->default('draft')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supplies', function (Blueprint $table) {
            // Remettre les colonnes en enum si nécessaire
            $table->enum('type', ['internal', 'external'])->default('internal')->change();
            $table->enum('status', ['draft', 'open', 'pending', 'partially_delivered', 'delivered', 'not_delivered', 'rejected'])
                ->default('draft')
                ->change();
        });
    }
};
