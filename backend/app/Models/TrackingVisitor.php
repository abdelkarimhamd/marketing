<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrackingVisitor extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'visitor_id',
        'session_id',
        'lead_id',
        'email_hash',
        'phone_hash',
        'first_url',
        'last_url',
        'referrer',
        'utm_json',
        'traits_json',
        'first_ip',
        'last_ip',
        'user_agent',
        'first_seen_at',
        'last_seen_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'utm_json' => 'array',
            'traits_json' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * Lead identified for this visitor.
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * Tracking events associated with this visitor.
     */
    public function events(): HasMany
    {
        return $this->hasMany(TrackingEvent::class, 'visitor_id', 'visitor_id')
            ->where('tenant_id', (int) $this->tenant_id);
    }
}
