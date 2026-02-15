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
        Schema::create('restaurant_rooms', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('code')->unique();
            $table->string('rooms_number')->unique();
            $table->text('description')->nullable();
            $table->enum('type', ['simple', 'vip', 'deluxe'])->default('simple');
            $table->integer('capacity')->default(1);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurant_rooms');
    }
};
