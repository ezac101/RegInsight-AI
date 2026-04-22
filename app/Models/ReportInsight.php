<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportInsight extends Model
{
    protected $fillable = [
        'financial_report_id',
        'insight_type',
        'content',
        'supporting_data',
        'confidence_score',
        'model_used',
        'is_shown_on_dashboard',
    ];

    protected $casts = [
        'supporting_data' => 'array',
        'confidence_score' => 'float',
        'is_shown_on_dashboard' => 'boolean',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(FinancialReport::class, 'financial_report_id');
    }
}
