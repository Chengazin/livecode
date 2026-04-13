<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectTask extends Model
{
    protected $primaryKey = 'project_task_id';

    protected $fillable = [
        'project_id',
        'created_by_user_id',
        'assigned_to_user_id',
        'title',
        'description',
        'status',
        'priority',
        'due_date',
        'assigned_at',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'assigned_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Task status enumeration
     * Simplified workflow: Backlog → In Progress → Done
     */
    public const STATUS_BACKLOG = 'backlog';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_DONE = 'done';

    /**
     * Task priority enumeration
     */
    public const PRIORITY_LOW = 0;
    public const PRIORITY_MEDIUM = 1;
    public const PRIORITY_HIGH = 2;
    public const PRIORITY_URGENT = 3;

    /**
     * Relationship to Project
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    /**
     * Relationship to task creator
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id', 'user_id');
    }

    /**
     * Relationship to task assignee
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id', 'user_id');
    }

    /**
     * Assign task to a user
     * Auto-transitions from Backlog to In Progress when assigned
     */
    public function assignTo(int $userId): void
    {
        $this->update([
            'assigned_to_user_id' => $userId,
            'assigned_at' => now(),
        ]);

        // Only move to In Progress if task is in Backlog
        if ($this->status === self::STATUS_BACKLOG && $this->assigned_to_user_id === $userId) {
            $this->update(['status' => self::STATUS_IN_PROGRESS]);
        }
    }

    /**
     * Start work on a task (move from Backlog to In Progress)
     * Requires an assignee
     */
    public function startWork(): void
    {
        if ($this->status !== self::STATUS_IN_PROGRESS) {
            $this->update([
                'status' => self::STATUS_IN_PROGRESS,
                'started_at' => now(),
            ]);
        }
    }

    /**
     * Mark task as done
     */
    public function markDone(): void
    {
        $this->update([
            'status' => self::STATUS_DONE,
            'completed_at' => now(),
        ]);
    }

    /**
     * Reopen task back to Backlog
     */
    public function reopen(): void
    {
        $this->update([
            'status' => self::STATUS_BACKLOG,
            'completed_at' => null,
        ]);
    }

    /**
     * Unassign a task (return to Backlog)
     */
    public function unassign(): void
    {
        $this->update([
            'assigned_to_user_id' => null,
            'status' => self::STATUS_BACKLOG,
            'assigned_at' => null,
        ]);
    }

    /**
     * Scope to get tasks for a project
     */
    public function scopeForProject($query, int $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    /**
     * Scope to get backlog tasks
     */
    public function scopeBacklog($query)
    {
        return $query->where('status', self::STATUS_BACKLOG);
    }

    /**
     * Scope to get active (in progress) tasks
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_IN_PROGRESS);
    }

    /**
     * Scope to get tasks assigned to a user
     */
    public function scopeAssignedTo($query, int $userId)
    {
        return $query->where('assigned_to_user_id', $userId);
    }

    /**
     * Scope to get overdue tasks
     */
    public function scopeOverdue($query)
    {
        return $query->whereDate('due_date', '<', now())
            ->where('status', '!=', self::STATUS_DONE);
    }
}
