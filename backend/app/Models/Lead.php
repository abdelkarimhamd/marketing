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
        'brand_id',
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
        'country_code',
        'interest',
        'service',
        'title',
        'status',
        'source',
        'score',
        'timezone',
        'locale',
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
     * Brand profile attribution for this lead.
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
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

    /**
     * Proof-of-consent history rows.
     */
    public function consentEvents(): HasMany
    {
        return $this->hasMany(ConsentEvent::class);
    }

    /**
     * Preference-center state.
     */
    public function preferences(): HasMany
    {
        return $this->hasMany(LeadPreference::class);
    }

    /**
     * Custom field values for this lead.
     */
    public function customFieldValues(): HasMany
    {
        return $this->hasMany(LeadCustomFieldValue::class);
    }

    /**
     * Attachments uploaded for this lead.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /**
     * Calls associated with this lead.
     */
    public function callLogs(): HasMany
    {
        return $this->hasMany(CallLog::class);
    }

    /**
     * Appointments associated with this lead.
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * Proposals generated for this lead.
     */
    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class);
    }

    /**
     * Accounts linked to this lead/contact.
     */
    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class, 'account_contacts')
            ->using(AccountContact::class)
            ->withPivot(['id', 'tenant_id', 'is_primary', 'job_title', 'meta'])
            ->withTimestamps();
    }

    /**
     * Web activity events mapped to this lead.
     */
    public function trackingEvents(): HasMany
    {
        return $this->hasMany(TrackingEvent::class);
    }

    /**
     * Visitor identities mapped to this lead.
     */
    public function trackingVisitors(): HasMany
    {
        return $this->hasMany(TrackingVisitor::class);
    }

    /**
     * AI-generated summaries for this lead.
     */
    public function aiSummaries(): HasMany
    {
        return $this->hasMany(AiSummary::class);
    }

    /**
     * AI-generated recommendations for this lead.
     */
    public function aiRecommendations(): HasMany
    {
        return $this->hasMany(AiRecommendation::class);
    }
}
