<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GaidDocument extends Model
{
    protected $fillable = [
        'gaid_submission_id',
        'parastatal',
        'document_type',
        'document_label',
        'file_path',
        'file_name',
        'file_hash',
        'file_size_kb',
        'mime_type',
        'extracted_text',
        'ai_analysis',
        'clauses_covered',
        'coverage_score',
        'processing_status',
        'processing_error',
    ];

    protected $casts = [
        'ai_analysis' => 'array',
        'clauses_covered' => 'array',
        'coverage_score' => 'float',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(GaidSubmission::class, 'gaid_submission_id');
    }

    public function gaps(): HasMany
    {
        return $this->hasMany(GaidGap::class);
    }
}
