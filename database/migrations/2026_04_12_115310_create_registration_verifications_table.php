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
        Schema::create('registration_verifications', function (Blueprint $table) {
            $table->id('registration_verification_id');
            $table->string('email', 255)->unique()->index();
            $table->string('name', 255);
            $table->string('password_hash', 512);
            $table->string('language', 3)->default('rus');
            $table->string('verification_code', 10);
            $table->integer('attempts')->default(0);
            $table->integer('max_attempts')->default(3);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index(['email', 'verification_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registration_verifications');
    }
};
