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
        // 🔹 Supprimer la table si elle existe
        Schema::dropIfExists('floors');

        Schema::create('floors', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('code')->unique(); // code en string maintenant
            $table->string('name');           // ex: "1er étage"
            $table->string('floor_number')->unique(); // floor_number en string
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('floors');
    }
};
