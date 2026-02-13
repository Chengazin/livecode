<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('git_enabled')->default(false)->after('is_public');
            $table->unsignedBigInteger('forgejo_repo_id')->nullable()->after('git_enabled');
            $table->string('forgejo_repo_full_name', 255)->nullable();
            $table->string('forgejo_repo_clone_url', 2048)->nullable();
            $table->string('forgejo_repo_html_url', 2048)->nullable();
            $table->string('forgejo_default_branch', 255)->nullable();
            $table->timestamp('forgejo_connected_at')->nullable();
            $table->timestamp('forgejo_last_push_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'git_enabled',
                'forgejo_repo_id',
                'forgejo_repo_full_name',
                'forgejo_repo_clone_url',
                'forgejo_repo_html_url',
                'forgejo_default_branch',
                'forgejo_connected_at',
                'forgejo_last_push_at',
            ]);
        });
    }
};
