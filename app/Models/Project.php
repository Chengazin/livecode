<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;

    protected $primaryKey = 'project_id';

    protected $fillable = [
        'name',
        'description',
        'owner_id',
        'project_path',
        'is_public',
    ];

    protected $casts = [
        'is_public' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id', 'user_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ProjectParticipant::class, 'project_id', 'project_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'project_participants',
            'project_id',
            'user_id',
            'project_id',
            'user_id'
        )->withPivot(['participant_id', 'joined_at']);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(ProjectInvitation::class, 'project_id', 'project_id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(ProjectSnapshot::class, 'project_id', 'project_id');
    }
}
