<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Update existing task statuses to new simplified workflow: backlog, in_progress, done
     */
    public function up(): void
    {
        // Map old statuses to new ones
        // 'open' -> 'backlog'
        // 'assigned' -> 'backlog' (if no assignee) or 'backlog' (backlog but with assignee)
        // 'in_progress' -> 'in_progress' (stays same)
        // 'completed' -> 'done'
        // 'closed' -> 'done'

        DB::table('project_tasks')
            ->where('status', 'open')
            ->update(['status' => 'backlog']);

        DB::table('project_tasks')
            ->where('status', 'assigned')
            ->update(['status' => 'backlog']);

        DB::table('project_tasks')
            ->where('status', 'completed')
            ->update(['status' => 'done']);

        DB::table('project_tasks')
            ->where('status', 'closed')
            ->update(['status' => 'done']);

        // 'in_progress' stays as 'in_progress'
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverse mapping (best effort, some information is lost)
        DB::table('project_tasks')
            ->where('status', 'backlog')
            ->update(['status' => 'open']);

        DB::table('project_tasks')
            ->where('status', 'done')
            ->update(['status' => 'completed']);
    }
};
