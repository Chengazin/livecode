<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id('project_id');
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->foreignId('owner_id')->constrained('users', 'user_id')->cascadeOnDelete();
            $table->string('project_path', 2048)->unique();
            $table->boolean('is_public')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index('owner_id', 'idx_projects_owner');
        });

        // Правильное создание индекса с сортировкой DESC через сырой SQL
        DB::statement('CREATE INDEX idx_projects_created_at ON projects (created_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
