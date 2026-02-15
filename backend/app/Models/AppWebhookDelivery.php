<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppWebhookDelivery extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'app_webhook_id',
        'domain_event_id',
        'attempt_no',
        'status',
        'response_code',
        'error_message',
        'request_headers',
        'request_payload',
        'delivered_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt_no' => 'integer',
            'response_code' => 'integer',
            'request_headers' => 'array',
            'request_payload' => 'array',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * Webhook relation.
     */
    public function webhook(): BelongsTo
    {
        return $this->belongsTo(AppWebhook::class, 'app_webhook_id');
    }

    /**
     * Domain event relation.
     */
    public function domainEvent(): BelongsTo
    {
        return $this->belongsTo(DomainEvent::class);
    }
}
