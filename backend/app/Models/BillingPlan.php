<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingPlan extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'seat_limit',
        'message_bundle',
        'monthly_price',
        'overage_price_per_message',
        'hard_limit',
        'addons',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seat_limit' => 'integer',
            'message_bundle' => 'integer',
            'monthly_price' => 'decimal:2',
            'overage_price_per_message' => 'decimal:4',
            'hard_limit' => 'boolean',
            'addons' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(TenantSubscription::class);
    }
}

