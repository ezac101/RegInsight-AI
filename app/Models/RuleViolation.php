<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RuleViolation extends Model
{
    protected $fillable = [
        'financial_report_id', 'validation_rule_id', 'report_field_id',
        'violation_detail', 'ai_explanation', 'ai_confidence',
        'status', 'resolution_note', 'resolved_by', 'resolved_at',
    ];

    protected $casts = [
        'resolved_at'    => 'datetime',
        'ai_confidence'  => 'float',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(FinancialReport::class, 'financial_report_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(ValidationRule::class, 'validation_rule_id');
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(ReportField::class, 'report_field_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOpen($q)   { return $q->where('status', 'open'); }
    public function scopeCritical($q)
    {
        return $q->whereHas('rule', fn($r) => $r->where('severity', 'critical'));
    }
}
