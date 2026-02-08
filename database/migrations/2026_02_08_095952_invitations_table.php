<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_invitations', function (Blueprint $table) {
            $table->id('invitation_id');
            $table->foreignId('project_id')->constrained('projects', 'project_id')->cascadeOnDelete();
            $table->foreignId('inviter_user_id')->constrained('users', 'user_id')->cascadeOnDelete();
            $table->string('invite_token', 128)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('invite_token', 'idx_project_invitations_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_invitations');
    }
};
