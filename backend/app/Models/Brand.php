<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Brand extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'is_active',
        'email_from_address',
        'email_from_name',
        'email_reply_to',
        'sms_sender_id',
        'whatsapp_phone_number_id',
        'landing_domain',
        'landing_page',
        'branding',
        'signatures',
        'settings',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'landing_page' => 'array',
            'branding' => 'array',
            'signatures' => 'array',
            'settings' => 'encrypted:array',
        ];
    }

    /**
     * Tenant that owns this brand.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Templates that belong to this brand.
     */
    public function templates(): HasMany
    {
        return $this->hasMany(Template::class);
    }

    /**
     * Campaigns assigned to this brand.
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /**
     * Leads attributed to this brand.
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /**
     * Messages sent/received under this brand.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Proposal templates linked to this brand.
     */
    public function proposalTemplates(): HasMany
    {
        return $this->hasMany(ProposalTemplate::class);
    }

    /**
     * Proposals linked to this brand.
     */
    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class);
    }
}
