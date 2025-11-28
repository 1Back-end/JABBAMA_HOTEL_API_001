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
        Schema::create('passation_managers', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('passation_uuid');
            $table->unsignedBigInteger('manager_id');

            $table->string('status')->default('pending');

            $table->timestamps();

            $table->foreign('passation_uuid')
                ->references('uuid')->on('passations')
                ->onDelete('cascade');

            $table->foreign('manager_id')
                ->references('id')->on('users')
                ->onDelete('cascade');
            $table->softDeletes();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('passation_managers');
    }
};
