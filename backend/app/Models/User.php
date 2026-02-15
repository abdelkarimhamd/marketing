<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\BelongsToTenant;
use App\Support\PermissionMatrix;
use App\Tenancy\TenantContext;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;
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
        'settings',
        'last_seen_at',
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
            'settings' => 'array',
            'last_seen_at' => 'datetime',
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
     * Tenant-scoped custom roles assigned to this user.
     */
    public function tenantRoles(): BelongsToMany
    {
        return $this->belongsToMany(TenantRole::class, 'tenant_role_user')
            ->withPivot(['tenant_id'])
            ->withTimestamps();
    }

    /**
     * Determine if user has a specific tenant permission.
     */
    public function hasPermission(string $permission, ?int $tenantId = null): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $resolvedTenantId = $this->resolvePermissionTenantId($tenantId);

        if ($resolvedTenantId === null) {
            return false;
        }

        if ($this->isTenantAdmin() && $this->belongsToTenant($resolvedTenantId)) {
            return true;
        }

        return app(PermissionMatrix::class)->allows(
            $this->effectivePermissionMatrix($resolvedTenantId),
            $permission
        );
    }

    /**
     * Build effective permission matrix for one tenant.
     *
     * @return array<string, array<string, bool>>
     */
    public function effectivePermissionMatrix(?int $tenantId = null): array
    {
        $matrix = app(PermissionMatrix::class);

        if ($this->isSuperAdmin()) {
            return $matrix->fullMatrix();
        }

        $resolvedTenantId = $this->resolvePermissionTenantId($tenantId);

        if ($resolvedTenantId === null) {
            return $matrix->blankMatrix();
        }

        if ($this->isTenantAdmin() && $this->belongsToTenant($resolvedTenantId)) {
            return $matrix->fullMatrix();
        }

        $roleMatrices = DB::table('tenant_role_user')
            ->join('tenant_roles', 'tenant_roles.id', '=', 'tenant_role_user.tenant_role_id')
            ->where('tenant_role_user.tenant_id', $resolvedTenantId)
            ->where('tenant_role_user.user_id', $this->id)
            ->where('tenant_roles.tenant_id', $resolvedTenantId)
            ->pluck('tenant_roles.permissions')
            ->all();

        $effective = $matrix->blankMatrix();

        foreach ($roleMatrices as $rawPermissions) {
            if (is_string($rawPermissions)) {
                $decoded = json_decode($rawPermissions, true);
            } elseif (is_array($rawPermissions)) {
                $decoded = $rawPermissions;
            } else {
                $decoded = null;
            }

            if (! is_array($decoded)) {
                continue;
            }

            $effective = $matrix->mergeMatrices($effective, $decoded);
        }

        return $effective;
    }

    /**
     * Determine whether user has any tenant permissions at all.
     */
    public function hasAnyPermission(?int $tenantId = null): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $flat = app(PermissionMatrix::class)->flattenMatrix(
            $this->effectivePermissionMatrix($tenantId)
        );

        return in_array(true, $flat, true);
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
     * Appointments owned by this user.
     */
    public function appointmentsOwned(): HasMany
    {
        return $this->hasMany(Appointment::class, 'owner_id');
    }

    /**
     * Appointments created by this user.
     */
    public function appointmentsCreated(): HasMany
    {
        return $this->hasMany(Appointment::class, 'created_by');
    }

    /**
     * Proposals created by this user.
     */
    public function proposalsCreated(): HasMany
    {
        return $this->hasMany(Proposal::class, 'created_by');
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
     * Call logs handled by this user.
     */
    public function callLogs(): HasMany
    {
        return $this->hasMany(CallLog::class);
    }

    /**
     * AI assistant requests initiated by this user.
     */
    public function aiInteractions(): HasMany
    {
        return $this->hasMany(AiInteraction::class);
    }

    /**
     * Account ownership relation.
     */
    public function ownedAccounts(): HasMany
    {
        return $this->hasMany(Account::class, 'owner_user_id');
    }

    /**
     * Registered mobile device tokens.
     */
    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    /**
     * Telephony call rows.
     */
    public function calls(): HasMany
    {
        return $this->hasMany(Call::class);
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

    /**
     * Resolve tenant id for permission checks.
     */
    private function resolvePermissionTenantId(?int $tenantId): ?int
    {
        if ($tenantId !== null && $tenantId > 0) {
            return $tenantId;
        }

        $contextTenantId = app(TenantContext::class)->tenantId();

        if ($contextTenantId !== null && $contextTenantId > 0) {
            return $contextTenantId;
        }

        return $this->tenant_id !== null ? (int) $this->tenant_id : null;
    }
}
