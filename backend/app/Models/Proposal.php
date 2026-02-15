<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Proposal extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'brand_id',
        'lead_id',
        'proposal_template_id',
        'created_by',
        'pdf_attachment_id',
        'version_no',
        'status',
        'service',
        'currency',
        'quote_amount',
        'title',
        'subject',
        'body_html',
        'body_text',
        'share_token',
        'public_url',
        'accepted_by',
        'sent_at',
        'opened_at',
        'accepted_at',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'quote_amount' => 'decimal:2',
            'sent_at' => 'datetime',
            'opened_at' => 'datetime',
            'accepted_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /**
     * Lead linked to this proposal.
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * Template used to generate this proposal.
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ProposalTemplate::class, 'proposal_template_id');
    }

    /**
     * Brand associated with this proposal.
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Attachment row containing generated PDF.
     */
    public function pdfAttachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'pdf_attachment_id');
    }

    /**
     * Creator user.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
