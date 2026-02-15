<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadImportSchedule extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'preset_id',
        'name',
        'source_type',
        'source_config',
        'mapping',
        'defaults',
        'dedupe_policy',
        'dedupe_keys',
        'auto_assign',
        'schedule_cron',
        'timezone',
        'is_active',
        'last_processed_count',
        'last_status',
        'last_run_at',
        'next_run_at',
        'last_error',
        'settings',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_config' => 'encrypted:array',
            'mapping' => 'array',
            'defaults' => 'array',
            'dedupe_keys' => 'array',
            'settings' => 'array',
            'auto_assign' => 'boolean',
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    /**
     * Linked import mapping preset.
     */
    public function preset(): BelongsTo
    {
        return $this->belongsTo(LeadImportPreset::class, 'preset_id');
    }

    /**
     * Creator user (if any).
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Last updater user (if any).
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

