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
        Schema::create('medias', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->morphs('mediable');
            $table->string('name');
            $table->string('disk');
            $table->string('path');
            $table->string('filename');
            $table->string('mimetype');
            $table->string('extension');
            $table->integer('validity')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('medias');
    }
};
