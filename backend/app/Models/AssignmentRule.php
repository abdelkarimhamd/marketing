<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentRule extends Model
{
    use BelongsToTenant, HasFactory;

    public const STRATEGY_ROUND_ROBIN = 'round_robin';

    public const STRATEGY_CITY = 'city';

    public const STRATEGY_INTEREST_SERVICE = 'interest_service';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'team_id',
        'last_assigned_user_id',
        'fallback_owner_id',
        'name',
        'is_active',
        'priority',
        'strategy',
        'auto_assign_on_intake',
        'auto_assign_on_import',
        'conditions',
        'settings',
        'last_assigned_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'priority' => 'integer',
            'auto_assign_on_intake' => 'boolean',
            'auto_assign_on_import' => 'boolean',
            'conditions' => 'array',
            'settings' => 'encrypted:array',
            'last_assigned_at' => 'datetime',
        ];
    }

    /**
     * Team this rule applies to.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * User most recently assigned by this rule.
     */
    public function lastAssignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_assigned_user_id');
    }

    /**
     * Fallback owner used when strategy cannot resolve dynamically.
     */
    public function fallbackOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fallback_owner_id');
    }
}
