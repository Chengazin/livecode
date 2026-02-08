<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_snapshots', function (Blueprint $table) {
            $table->id('snapshot_id');
            $table->foreignId('project_id')->constrained('projects', 'project_id')->cascadeOnDelete();
            $table->string('snapshot_hash', 64)->unique();
            $table->foreignId('author_user_id')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->text('message')->nullable();
            $table->string('snapshot_path', 2048);
            $table->bigInteger('size_bytes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('project_id', 'idx_project_snapshots_project');
        });

        // Правильное создание индекса с сортировкой DESC через сырой SQL
        DB::statement('CREATE INDEX idx_project_snapshots_created_at ON project_snapshots (created_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('project_snapshots');
    }
};
