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
        Schema::create('menu_restaurant_complements', function (Blueprint $table) {
            $table->uuid('uuid')->primary();

            $table->uuid('menu_restaurant_uuid');
            $table->uuid('complement_uuid');

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->foreign('menu_restaurant_uuid')
                ->references('uuid')
                ->on('menus_restaurants')
                ->cascadeOnDelete();

            $table->foreign('complement_uuid')
                ->references('uuid')
                ->on('configurations_complements')
                ->cascadeOnDelete();

            $table->index('menu_restaurant_uuid');
            $table->index('complement_uuid');

            $table->softDeletes();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('menu_restaurant_complements');
    }
};
