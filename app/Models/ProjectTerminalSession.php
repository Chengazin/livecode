<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectTerminalSession extends Model
{
    use HasFactory;

    protected $table = 'project_terminal_sessions';

    protected $primaryKey = 'terminal_session_id';

    protected $fillable = [
        'project_id',
        'user_id',
        'name',
        'shell',
        'cwd',
        'shared',
        'status',
        'meta',
        'last_activity_at',
        'closed_at',
    ];

    protected $casts = [
        'shared' => 'boolean',
        'meta' => 'array',
        'last_activity_at' => 'datetime',
        'closed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function isOpen(): bool
    {
        return (string) $this->status === 'open' && $this->closed_at === null;
    }
}
