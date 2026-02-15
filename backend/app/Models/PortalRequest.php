<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortalRequest extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'lead_id',
        'account_id',
        'request_type',
        'status',
        'payload_json',
        'meta',
        'source_ip',
        'user_agent',
        'assigned_to',
        'converted_by',
        'converted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'meta' => 'array',
            'converted_at' => 'datetime',
        ];
    }

    /**
     * Linked lead.
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * Linked account.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Assignee relation.
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Alias for assignee relation.
     */
    public function assignedTo(): BelongsTo
    {
        return $this->assignee();
    }

    /**
     * Converter relation.
     */
    public function converter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_by');
    }

    /**
     * Alias for converter relation.
     */
    public function convertedBy(): BelongsTo
    {
        return $this->converter();
    }
}
