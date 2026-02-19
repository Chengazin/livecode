<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectParticipant extends Model
{
    use HasFactory;

    public const ROLE_VIEWER = 'viewer';

    public const ROLE_DEVELOPER = 'developer';

    public const ROLE_MAINTAINER = 'maintainer';

    protected $primaryKey = 'participant_id';

    public $timestamps = false;

    protected $fillable = [
        'project_id',
        'user_id',
        'role',
        'joined_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
    ];

    /**
     * @return list<string>
     */
    public static function roles(): array
    {
        return [
            self::ROLE_VIEWER,
            self::ROLE_DEVELOPER,
            self::ROLE_MAINTAINER,
        ];
    }

    public static function normalizeRole(?string $role): string
    {
        $value = strtolower(trim((string) $role));
        if (! in_array($value, self::roles(), true)) {
            return self::ROLE_DEVELOPER;
        }

        return $value;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
