<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Message extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'brand_id',
        'campaign_id',
        'campaign_step_id',
        'lead_id',
        'template_id',
        'user_id',
        'direction',
        'status',
        'channel',
        'thread_key',
        'to',
        'from',
        'subject',
        'body',
        'provider',
        'provider_message_id',
        'in_reply_to',
        'reply_token',
        'reply_to_email',
        'error_message',
        'compliance_block_reason',
        'cost_estimate',
        'provider_cost',
        'overhead_cost',
        'revenue_amount',
        'profit_amount',
        'margin_percent',
        'cost_tracked_at',
        'cost_currency',
        'meta',
        'sent_at',
        'delivered_at',
        'opened_at',
        'clicked_at',
        'read_at',
        'failed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'opened_at' => 'datetime',
            'clicked_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
            'cost_estimate' => 'decimal:4',
            'provider_cost' => 'decimal:4',
            'overhead_cost' => 'decimal:4',
            'revenue_amount' => 'decimal:4',
            'profit_amount' => 'decimal:4',
            'margin_percent' => 'decimal:4',
            'cost_tracked_at' => 'datetime',
        ];
    }

    /**
     * Campaign this message belongs to.
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * Brand profile associated with this message.
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Step that generated this message.
     */
    public function campaignStep(): BelongsTo
    {
        return $this->belongsTo(CampaignStep::class);
    }

    /**
     * Lead this message targets.
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * Template used by this message.
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /**
     * Sender user for this message.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Activities recorded against this message.
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }
}
