<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectSnapshot extends Model
{
    use HasFactory;

    protected $primaryKey = 'snapshot_id';

    public $timestamps = false;

    protected $fillable = [
        'project_id',
        'snapshot_hash',
        'author_user_id',
        'message',
        'snapshot_path',
        'size_bytes',
        'created_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'created_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id', 'user_id');
    }
}
