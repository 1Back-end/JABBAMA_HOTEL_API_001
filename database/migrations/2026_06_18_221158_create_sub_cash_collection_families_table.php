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
        Schema::create('sub_cash_collection_families', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->uuid('cash_collection_family_uuid');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('code')->unique();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();


            $table->foreign('cash_collection_family_uuid')
                ->references('uuid')
                ->on('cash_collection_families')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sub_cash_collection_families');
    }
};
