<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_terminal_sessions', function (Blueprint $table) {
            $table->id('terminal_session_id');
            $table->foreignId('project_id')->constrained('projects', 'project_id')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users', 'user_id')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('shell', 120)->nullable();
            $table->string('cwd', 2048)->default('/');
            $table->boolean('shared')->default(false);
            $table->string('status', 20)->default('open');
            $table->json('meta')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index(['project_id', 'status'], 'idx_terminal_sessions_project_status');
            $table->index(['project_id', 'user_id', 'status'], 'idx_terminal_sessions_project_user_status');
            $table->index(['status', 'updated_at'], 'idx_terminal_sessions_status_updated');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_terminal_sessions');
    }
};
