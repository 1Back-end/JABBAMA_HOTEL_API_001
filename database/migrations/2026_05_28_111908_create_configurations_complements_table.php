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
        Schema::create('configurations_complements', function (Blueprint $table) {
            $table->uuid('uuid')->primary();

            $table->string('code')->unique();

            $table->json('prices_for_clients_debtor')->nullable();
            $table->json('prices_for_clients_partner')->nullable();
            $table->json('prices_for_clients_free')->nullable();
            $table->string('description')->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_confectioned')->default(false);

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->uuid('menus_restaurant_uuid');
            $table->string('menus_complement_type')->nullable();

            $table->foreign('menus_restaurant_uuid')->references('uuid')->on('menus_restaurants')->onDelete('cascade');


            $table->timestamps();
            $table->softDeletes();


        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('configurations_complements');
    }
};
