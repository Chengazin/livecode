<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id('user_id');
            $table->string('name', 255);
            $table->string('email', 255)->unique();
            $table->string('password_hash', 512);
            $table->string('status', 20)->default('active');
            $table->string('language', 3)->default('rus');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('last_seen')->nullable();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void    
    {
        Schema::dropIfExists('users');
    }
};
