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
     */
    public const STATUS_OPEN = 'open';
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CLOSED = 'closed';

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
     */
    public function assignTo(int $userId): void
    {
        $this->update([
            'assigned_to_user_id' => $userId,
            'status' => self::STATUS_ASSIGNED,
            'assigned_at' => now(),
        ]);
    }

    /**
     * Start working on the task
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
     * Complete the task
     */
    public function complete(): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }

    /**
     * Close the task
     */
    public function close(): void
    {
        $this->update([
            'status' => self::STATUS_CLOSED,
        ]);
    }

    /**
     * Unassign the task
     */
    public function unassign(): void
    {
        $this->update([
            'assigned_to_user_id' => null,
            'status' => self::STATUS_OPEN,
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
     * Scope to get open tasks
     */
    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /**
     * Scope to get assigned tasks
     */
    public function scopeAssigned($query)
    {
        return $query->where('status', '!=', self::STATUS_OPEN)
            ->where('assigned_to_user_id', '!=', null);
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
            ->where('status', '!=', self::STATUS_COMPLETED)
            ->where('status', '!=', self::STATUS_CLOSED);
    }
}
