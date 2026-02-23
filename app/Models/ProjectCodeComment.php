<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectCodeComment extends Model
{
    use HasFactory;

    protected $primaryKey = 'comment_id';

    public $timestamps = false;

    protected $fillable = [
        'project_id',
        'author_user_id',
        'path',
        'line_number',
        'body',
        'created_at',
    ];

    protected $casts = [
        'line_number' => 'integer',
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

