<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_participants', function (Blueprint $table) {
            $table->id('participant_id');
            $table->foreignId('project_id')->constrained('projects', 'project_id')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users', 'user_id')->cascadeOnDelete();
            $table->timestamp('joined_at')->useCurrent();

            $table->unique(['project_id', 'user_id']);
            $table->index('user_id', 'idx_project_participants_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_participants');
    }
};
