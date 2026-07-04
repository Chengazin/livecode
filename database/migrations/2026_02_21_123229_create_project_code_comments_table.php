<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_code_comments', function (Blueprint $table) {
            $table->id('comment_id');
            $table->foreignId('project_id')->constrained('projects', 'project_id')->cascadeOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->string('path', 191);
            $table->unsignedInteger('line_number');
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['project_id', 'path', 'line_number'], 'idx_project_code_comments_file_line');
            $table->index('author_user_id', 'idx_project_code_comments_author');
        });

        DB::statement('CREATE INDEX idx_project_code_comments_created_at ON project_code_comments (created_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('project_code_comments');
    }
};

