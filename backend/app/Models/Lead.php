<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'team_id',
        'owner_id',
        'first_name',
        'last_name',
        'email',
        'email_consent',
        'consent_updated_at',
        'phone',
        'company',
        'city',
        'interest',
        'service',
        'title',
        'status',
        'source',
        'score',
        'timezone',
        'last_contacted_at',
        'next_follow_up_at',
        'settings',
        'meta',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_consent' => 'boolean',
            'consent_updated_at' => 'datetime',
            'last_contacted_at' => 'datetime',
            'next_follow_up_at' => 'datetime',
            'settings' => 'encrypted:array',
            'meta' => 'array',
        ];
    }

    /**
     * Team assigned to this lead.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Owner user assigned to this lead.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Tags linked to this lead.
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'lead_tag')
            ->withPivot(['tenant_id'])
            ->withTimestamps();
    }

    /**
     * Messages for this lead.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Activities recorded against this lead.
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    /**
     * Unsubscribe records for this lead.
     */
    public function unsubscribes(): HasMany
    {
        return $this->hasMany(Unsubscribe::class);
    }
}
