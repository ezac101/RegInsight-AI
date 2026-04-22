<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GaidObligation extends Model
{
    protected $fillable = [
        'gaid_submission_id',
        'clause_reference',
        'obligation_title',
        'obligation_description',
        'plain_language_explanation',
        'deadline',
        'penalty_exposure',
        'category',
        'is_mandatory',
        'priority',
    ];

    protected $casts = [
        'deadline' => 'date',
        'is_mandatory' => 'boolean',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(GaidSubmission::class, 'gaid_submission_id');
    }

    public function gap(): HasOne
    {
        return $this->hasOne(GaidGap::class);
    }
}
