<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Account extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'owner_user_id',
        'name',
        'domain',
        'industry',
        'size',
        'city',
        'country',
        'notes',
        'settings',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /**
     * Owner assigned to this account.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * Contacts (leads) linked to this account.
     */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Lead::class, 'account_contacts')
            ->using(AccountContact::class)
            ->withPivot(['id', 'tenant_id', 'is_primary', 'job_title', 'meta'])
            ->withTimestamps();
    }

    /**
     * Direct pivot rows for account contacts.
     */
    public function accountContacts(): HasMany
    {
        return $this->hasMany(AccountContact::class);
    }
}
