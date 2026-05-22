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
        Schema::create('categories_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('libelle')->unique()->comment('Nom de la catégorie de permissions');
            $table->string('description')->nullable()->comment('Description de la catégorie');
            $table->boolean('active')->default(true)->comment('Statut actif/inactif');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories_permissions');
    }
};
