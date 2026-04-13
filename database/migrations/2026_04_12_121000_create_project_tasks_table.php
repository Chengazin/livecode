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
        Schema::create('project_tasks', function (Blueprint $table) {
            $table->id('project_task_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('created_by_user_id');  // Task creator (maintainer/owner)
            $table->unsignedBigInteger('assigned_to_user_id')->nullable();  // Task assignee
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->enum('status', ['backlog', 'in_progress', 'done'])->default('backlog');
            $table->integer('priority')->default(0);  // 0 = low, 1 = medium, 2 = high, 3 = urgent
            $table->date('due_date')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Indexes for quick lookups
            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'assigned_to_user_id']);
            $table->index(['created_by_user_id']);
            $table->index('due_date');

            // Foreign keys
            $table->foreign('project_id')
                ->references('project_id')
                ->on('projects')
                ->onDelete('cascade');
            $table->foreign('created_by_user_id')
                ->references('user_id')
                ->on('users')
                ->onDelete('cascade');
            $table->foreign('assigned_to_user_id')
                ->references('user_id')
                ->on('users')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_tasks');
    }
};
