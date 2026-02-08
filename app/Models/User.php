<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $primaryKey = 'user_id';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password_hash',
        'status',
        'language',
        'last_seen',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password_hash',
    ];

    /**
     * Override the auth password field name.
     */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password_hash' => 'hashed',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'last_seen' => 'datetime',
        ];
    }

    public function ownedProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'owner_id', 'user_id');
    }

    public function projectParticipants(): HasMany
    {
        return $this->hasMany(ProjectParticipant::class, 'user_id', 'user_id');
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(
            Project::class,
            'project_participants',
            'user_id',
            'project_id',
            'user_id',
            'project_id'
        )->withPivot(['participant_id', 'joined_at']);
    }

    public function invitationsSent(): HasMany
    {
        return $this->hasMany(ProjectInvitation::class, 'inviter_user_id', 'user_id');
    }

    public function snapshotsAuthored(): HasMany
    {
        return $this->hasMany(ProjectSnapshot::class, 'author_user_id', 'user_id');
    }

    public function admin(): HasOne
    {
        return $this->hasOne(Admin::class, 'user_id', 'user_id');
    }
}
