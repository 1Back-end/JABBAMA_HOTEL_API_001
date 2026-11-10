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
        Schema::create('licenses', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('format')->default('sgl-license-clear-v1');
            $table->string('issuer')->default('SGL');

            $table->string('client_external_id')->nullable();
            $table->string('client_name')->nullable();
            $table->string('client_email')->nullable();


            $table->string('app_id')->nullable();
            $table->string('app_code')->nullable();
            $table->string('app_name')->nullable();


            $table->string('license_id')->nullable();
            $table->string('reference')->nullable();
            $table->string('license_key')->unique();
            $table->string('status');
            $table->string('jti')->nullable();
            $table->string('kid')->nullable();

            $table->json('modules')->nullable();
            $table->json('module_entitlements')->nullable();

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('generated_at')->nullable();

            $table->string('signature_alg')->nullable();
            $table->string('signature_kid')->nullable();
            $table->string('signature_encoding')->nullable();
            $table->text('signature_value')->nullable();

            $table->longText('raw_certificate')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('licenses');
    }
};
