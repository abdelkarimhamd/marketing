<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Experiment extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'scope',
        'status',
        'holdout_pct',
        'start_at',
        'end_at',
        'config_json',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'holdout_pct' => 'float',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'config_json' => 'array',
        ];
    }

    /**
     * Variants linked to this experiment.
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ExperimentVariant::class);
    }

    /**
     * Assignment rows.
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(ExperimentAssignment::class);
    }

    /**
     * Metrics rows.
     */
    public function metrics(): HasMany
    {
        return $this->hasMany(ExperimentMetric::class);
    }
}
