<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CountryComplianceRule extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'country_code',
        'channel',
        'sender_id',
        'opt_out_keywords',
        'template_constraints',
        'is_active',
        'settings',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opt_out_keywords' => 'array',
            'template_constraints' => 'array',
            'is_active' => 'boolean',
            'settings' => 'encrypted:array',
        ];
    }
}

