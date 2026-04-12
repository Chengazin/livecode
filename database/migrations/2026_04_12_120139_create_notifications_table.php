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
        Schema::create('notifications', function (Blueprint $table) {
            $table->id('notification_id');
            $table->unsignedBigInteger('user_id');
            $table->string('type', 50);  // invitation_created, invitation_declined, participant_added, participant_removed, role_changed, etc.
            $table->string('title', 255);
            $table->text('message');
            $table->json('data')->nullable();  // Additional data (project_name, user_name, old_role, new_role, etc.)
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            // Indexes
            $table->index(['user_id', 'is_read']);
            $table->index(['user_id', 'created_at']);
            $table->foreign('user_id')
                ->references('user_id')
                ->on('users')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
