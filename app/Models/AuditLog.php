<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public $timestamps = false;             // uses performed_at only

    protected $fillable = [
        'entity_type',
        'entity_id',
        'action',
        'before',
        'after',
        'note',
        'performed_by',
        'ip_address',
        'user_agent',
        'performed_at',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'performed_at' => 'datetime',
    ];

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /**
     * Convenience logger — call from observers or controllers.
     */
    public static function record(
        string $entityType,
        int $entityId,
        string $action,
        array $before = [],
        array $after = [],
        string $note = ''
    ): self {
        return self::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'note' => $note,
            'performed_by' => auth()->id(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'performed_at' => now(),
        ]);
    }
}
