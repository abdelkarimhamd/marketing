<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalizationVariant extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'personalization_rule_id',
        'variant_key',
        'weight',
        'is_control',
        'changes_json',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weight' => 'integer',
            'is_control' => 'boolean',
            'changes_json' => 'array',
        ];
    }

    /**
     * Parent rule relation.
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(PersonalizationRule::class, 'personalization_rule_id');
    }
}
