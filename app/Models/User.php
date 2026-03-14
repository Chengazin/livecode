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
        'theme',
        'avatar_type',
        'avatar_preset',
        'avatar_path',
        'last_seen',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password_hash',
        'forgejo_access_token',
        'forgejo_refresh_token',
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
            'forgejo_access_token' => 'encrypted',
            'forgejo_refresh_token' => 'encrypted',
            'forgejo_token_expires_at' => 'datetime',
            'forgejo_connected_at' => 'datetime',
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
        )->withPivot(['participant_id', 'role', 'joined_at']);
    }

    public function invitationsSent(): HasMany
    {
        return $this->hasMany(ProjectInvitation::class, 'inviter_user_id', 'user_id');
    }

    public function admin(): HasOne
    {
        return $this->hasOne(Admin::class, 'user_id', 'user_id');
    }
}
