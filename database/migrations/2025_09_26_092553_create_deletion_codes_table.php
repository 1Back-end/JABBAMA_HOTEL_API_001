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
        Schema::create('deletion_codes', function (Blueprint $table) {
            $table->uuid('uuid')->primary(); // Clé primaire UUID
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();     // Utilisateur qui a généré le code
            $table->string('target_type');   // Type de ressource à supprimer (ex: supplier, employee)
            $table->uuid('target_uuid');       // ID de la ressource à supprimer
            $table->string('code', 20);       // Code OTP à 6 chiffres
            $table->boolean('is_used')->default(false); // Code utilisé ou non
            $table->timestamp('expires_at');        // Date d'expiration
            $table->timestamps();

            // Index pour accélérer la recherche par user et target
            $table->index(['user_id', 'target_type', 'target_uuid']);
            $table->foreignId('created_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deletion_codes');
    }
};
