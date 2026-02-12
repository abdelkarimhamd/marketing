<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\BelongsToTenant;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use BelongsToTenant, HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'role',
        'password',
        'is_super_admin',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'role' => UserRole::class,
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    /**
     * Determine whether the user can bypass tenant scope restrictions.
     */
    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SuperAdmin || (bool) $this->is_super_admin;
    }

    /**
     * Determine whether the user is a tenant admin.
     */
    public function isTenantAdmin(): bool
    {
        return $this->role === UserRole::TenantAdmin;
    }

    /**
     * Determine whether the user is a sales user.
     */
    public function isSales(): bool
    {
        return $this->role === UserRole::Sales;
    }

    /**
     * Determine whether the user has any admin role.
     */
    public function isAdmin(): bool
    {
        return $this->isSuperAdmin() || $this->isTenantAdmin();
    }

    /**
     * Determine whether user belongs to the given tenant.
     */
    public function belongsToTenant(?int $tenantId): bool
    {
        return $tenantId !== null && (int) $this->tenant_id === $tenantId;
    }

    /**
     * Teams the user belongs to.
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_user')
            ->withPivot(['tenant_id', 'role', 'is_primary'])
            ->withTimestamps();
    }

    /**
     * Team memberships for this user.
     */
    public function teamMemberships(): HasMany
    {
        return $this->hasMany(TeamUser::class);
    }

    /**
     * Leads owned by this user.
     */
    public function ownedLeads(): HasMany
    {
        return $this->hasMany(Lead::class, 'owner_id');
    }

    /**
     * Activities created by this user.
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class, 'actor_id');
    }

    /**
     * Campaigns created by this user.
     */
    public function createdCampaigns(): HasMany
    {
        return $this->hasMany(Campaign::class, 'created_by');
    }

    /**
     * Messages sent by this user.
     */
    public function sentMessages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Assignment rules last touched by this user.
     */
    public function assignmentRulesAsLastAssigned(): HasMany
    {
        return $this->hasMany(AssignmentRule::class, 'last_assigned_user_id');
    }

    /**
     * API keys created by this user.
     */
    public function createdApiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class, 'created_by');
    }

    /**
     * Keep legacy is_super_admin flag in sync with role for compatibility.
     */
    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if ($user->role === UserRole::SuperAdmin) {
                $user->is_super_admin = true;
                $user->tenant_id = null;

                return;
            }

            $user->is_super_admin = false;
        });
    }
}
