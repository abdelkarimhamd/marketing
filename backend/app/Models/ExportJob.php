<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExportJob extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'type',
        'destination',
        'status',
        'schedule_cron',
        'payload',
        'file_path',
        'last_run_at',
        'next_run_at',
        'completed_at',
        'error_message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}

