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
        Schema::create('warehouse_managers', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('warehouse_uuid'); // Référence vers l'entrepôt
            $table->foreignId('user_id')->constrained('users'); // Référence vers l'utilisateur (manager)

            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();

            $table->foreign('warehouse_uuid')->references('uuid')->on('warehouses')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warehouse_managers');
    }
};
