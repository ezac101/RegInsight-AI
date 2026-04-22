<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiProcessingJob extends Model
{
    protected $fillable = [
        'financial_report_id',
        'job_type',
        'status',
        'input_payload',
        'output_payload',
        'error_message',
        'duration_seconds',
        'python_version',
        'model_used',
    ];

    protected $casts = [
        'input_payload' => 'array',
        'output_payload' => 'array',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(FinancialReport::class, 'financial_report_id');
    }
}
