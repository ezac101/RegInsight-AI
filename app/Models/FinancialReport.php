<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialReport extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title',
        'source_agency',
        'report_type',
        'fiscal_year',
        'quarter',
        'file_path',
        'file_hash',
        'status',
        'raw_metadata',
        'normalized_data',
        'total_amount',
        'currency',
        'submitted_by',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'raw_metadata' => 'array',
        'normalized_data' => 'array',
        'reviewed_at' => 'datetime',
        'total_amount' => 'decimal:2',
    ];

    // ─── Relationships ───────────────────────────────────────────────────────

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(ReportField::class);
    }

    public function violations(): HasMany
    {
        return $this->hasMany(RuleViolation::class);
    }

    public function aiJobs(): HasMany
    {
        return $this->hasMany(AiProcessingJob::class);
    }

    public function insights(): HasMany
    {
        return $this->hasMany(ReportInsight::class);
    }

    // ─── Scopes ──────────────────────────────────────────────────────────────

    public function scopePending($q)
    {
        return $q->where('status', 'pending');
    }
    public function scopeFlagged($q)
    {
        return $q->where('status', 'flagged');
    }
    public function scopeValidated($q)
    {
        return $q->where('status', 'validated');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    public function hasCriticalViolations(): bool
    {
        return $this->violations()
            ->whereHas('rule', fn($q) => $q->where('severity', 'critical'))
            ->where('status', 'open')
            ->exists();
    }

    public function openViolationCount(): int
    {
        return $this->violations()->where('status', 'open')->count();
    }
}
