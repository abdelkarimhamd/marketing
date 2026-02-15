<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Attachment extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'lead_id',
        'entity_type',
        'entity_id',
        'kind',
        'source',
        'title',
        'description',
        'storage_disk',
        'storage_path',
        'original_name',
        'mime_type',
        'extension',
        'size_bytes',
        'checksum_sha256',
        'visibility',
        'scan_status',
        'scanned_at',
        'scan_engine',
        'scan_result',
        'uploaded_by',
        'meta',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'meta' => 'array',
            'scanned_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Lead this attachment belongs to (when entity_type=lead).
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * User who uploaded this attachment.
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
