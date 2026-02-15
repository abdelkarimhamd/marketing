<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingUsageRecord extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'tenant_subscription_id',
        'channel',
        'period_date',
        'messages_count',
        'cost_total',
        'provider_cost_total',
        'overhead_cost_total',
        'revenue_total',
        'profit_total',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_date' => 'date',
            'messages_count' => 'integer',
            'cost_total' => 'decimal:4',
            'provider_cost_total' => 'decimal:4',
            'overhead_cost_total' => 'decimal:4',
            'revenue_total' => 'decimal:4',
            'profit_total' => 'decimal:4',
            'metadata' => 'array',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(TenantSubscription::class, 'tenant_subscription_id');
    }
}
