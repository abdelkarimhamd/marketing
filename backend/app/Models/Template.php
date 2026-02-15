<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Template extends Model
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
        'name',
        'slug',
        'channel',
        'subject',
        'content',
        'body_text',
        'whatsapp_template_name',
        'whatsapp_variables',
        'settings',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'whatsapp_variables' => 'array',
            'settings' => 'encrypted:array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Campaigns using this template.
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /**
     * Brand profile this template belongs to.
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Campaign steps using this template.
     */
    public function campaignSteps(): HasMany
    {
        return $this->hasMany(CampaignStep::class);
    }

    /**
     * Messages rendered from this template.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
