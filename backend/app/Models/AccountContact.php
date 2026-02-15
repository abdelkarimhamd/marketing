<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class AccountContact extends Pivot
{
    use BelongsToTenant, HasFactory;

    /**
     * @var string
     */
    protected $table = 'account_contacts';

    /**
     * @var bool
     */
    public $incrementing = true;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'account_id',
        'lead_id',
        'is_primary',
        'job_title',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'meta' => 'array',
        ];
    }

    /**
     * Account relation.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Lead relation.
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
