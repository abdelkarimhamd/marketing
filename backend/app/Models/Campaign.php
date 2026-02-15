<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Campaign extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    public const TYPE_BROADCAST = 'broadcast';

    public const TYPE_SCHEDULED = 'scheduled';

    public const TYPE_DRIP = 'drip';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PAUSED = 'paused';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'brand_id',
        'segment_id',
        'template_id',
        'team_id',
        'created_by',
        'name',
        'slug',
        'description',
        'channel',
        'campaign_type',
        'status',
        'start_at',
        'end_at',
        'launched_at',
        'settings',
        'metrics',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'launched_at' => 'datetime',
            'settings' => 'encrypted:array',
            'metrics' => 'array',
        ];
    }

    /**
     * Determine if the campaign is a drip workflow.
     */
    public function isDrip(): bool
    {
        return $this->campaign_type === self::TYPE_DRIP;
    }

    /**
     * Determine if the campaign is scheduled.
     */
    public function isScheduled(): bool
    {
        return $this->campaign_type === self::TYPE_SCHEDULED;
    }

    /**
     * Segment powering this campaign.
     */
    public function segment(): BelongsTo
    {
        return $this->belongsTo(Segment::class);
    }

    /**
     * Brand profile powering this campaign.
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Primary template for this campaign.
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /**
     * Team owning this campaign.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * User who created this campaign.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Steps in the campaign workflow.
     */
    public function steps(): HasMany
    {
        return $this->hasMany(CampaignStep::class);
    }

    /**
     * Messages generated for this campaign.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Activities recorded against this campaign.
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }
}
