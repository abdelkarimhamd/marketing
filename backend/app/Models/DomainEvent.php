<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DomainEvent extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'event_name',
        'subject_type',
        'subject_id',
        'payload',
        'occurred_at',
        'dispatched_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'dispatched_at' => 'datetime',
        ];
    }

    /**
     * Delivery logs linked to this domain event.
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(AppWebhookDelivery::class);
    }
}
