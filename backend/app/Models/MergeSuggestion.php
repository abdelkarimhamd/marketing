<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MergeSuggestion extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'candidate_a_id',
        'candidate_b_id',
        'reason',
        'confidence',
        'status',
        'meta',
        'reviewed_by',
        'reviewed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'meta' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * Lead candidate A.
     */
    public function candidateA(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'candidate_a_id');
    }

    /**
     * Lead candidate B.
     */
    public function candidateB(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'candidate_b_id');
    }

    /**
     * Reviewer relation.
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
