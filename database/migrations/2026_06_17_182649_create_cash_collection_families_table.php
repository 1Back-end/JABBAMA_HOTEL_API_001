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
        Schema::create('cash_collection_families', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('code')->unique()->comment('Ex: RESTO-CASH, BAR-E-PAY');
            $table->string('name')->comment('Nom de la famille: Espèces Restaurant, Mobile Money Bar, etc.');
            $table->string('target_sector')->default('all')->comment('Détermine si la famille s\'applique au Restaurant, au Bar ou aux deux');
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->index(['target_sector', 'is_active']);
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
        Schema::dropIfExists('cash_collection_families');
    }
};
