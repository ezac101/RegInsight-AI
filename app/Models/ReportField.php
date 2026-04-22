<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportField extends Model
{
    protected $fillable = [
        'financial_report_id',
        'original_key',
        'normalized_key',
        'original_value',
        'normalized_value',
        'field_type',
        'ai_confidence',
        'is_flagged',
        'flag_reason',
    ];

    protected $casts = ['is_flagged' => 'boolean', 'ai_confidence' => 'float'];

    public function report(): BelongsTo
    {
        return $this->belongsTo(FinancialReport::class, 'financial_report_id');
    }
}
