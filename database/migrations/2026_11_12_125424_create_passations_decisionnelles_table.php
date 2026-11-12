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
        Schema::dropIfExists('passations_decisionnelles');
        Schema::create('passations_decisionnelles', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('passation_uuid')->nullable();
            $table->foreign('passation_uuid')
                ->references('uuid')
                ->on('passations')
                ->cascadeOnDelete();
            $table->string('status')->nullable();
            $table->text('message')->nullable();
            $table->string('target')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('passations_decisionnelles');
    }
};
