<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GaidGap extends Model
{
    protected $fillable = [
        'gaid_submission_id',
        'gaid_obligation_id',
        'gaid_document_id',
        'status',
        'evidence_confidence',
        'gap_detail',
        'ai_recommendation',
        'risk_level',
    ];

    protected $casts = [
        'evidence_confidence' => 'float',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(GaidSubmission::class, 'gaid_submission_id');
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(GaidObligation::class, 'gaid_obligation_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(GaidDocument::class, 'gaid_document_id');
    }
}
