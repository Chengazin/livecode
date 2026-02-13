<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('forgejo_user_id')->nullable()->unique();
            $table->string('forgejo_username', 255)->nullable();
            $table->text('forgejo_access_token')->nullable();
            $table->text('forgejo_refresh_token')->nullable();
            $table->timestamp('forgejo_token_expires_at')->nullable();
            $table->timestamp('forgejo_connected_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['forgejo_user_id']);
            $table->dropColumn([
                'forgejo_user_id',
                'forgejo_username',
                'forgejo_access_token',
                'forgejo_refresh_token',
                'forgejo_token_expires_at',
                'forgejo_connected_at',
            ]);
        });
    }
};
