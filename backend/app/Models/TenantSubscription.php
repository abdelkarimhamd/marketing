<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantSubscription extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'billing_plan_id',
        'status',
        'seat_limit_override',
        'message_bundle_override',
        'overage_price_override',
        'current_period_start',
        'current_period_end',
        'provider',
        'provider_subscription_id',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seat_limit_override' => 'integer',
            'message_bundle_override' => 'integer',
            'overage_price_override' => 'decimal:4',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(BillingPlan::class, 'billing_plan_id');
    }

    public function usageRecords(): HasMany
    {
        return $this->hasMany(BillingUsageRecord::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(BillingInvoice::class);
    }
}

