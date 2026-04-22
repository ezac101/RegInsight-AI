<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GaidSubmission extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'reference_code',
        'organisation_name',
        'organisation_email',
        'parastatal',
        'sector',
        'data_subjects',
        'uses_ai',
        'processes_sensitive_data',
        'transfers_data_outside_nigeria',
        'questionnaire_answers',
        'dcpmi_tier',
        'car_filing_fee',
        'filing_deadline',
        'dpo_required',
        'dpia_required',
        'opa_decision',
        'obligations',
        'gap_analysis',
        'compliance_score',
        'status',
        'submitted_by',
        'car_generated_at',
        'submitted_at',
    ];

    protected $casts = [
        'questionnaire_answers' => 'array',
        'opa_decision' => 'array',
        'obligations' => 'array',
        'gap_analysis' => 'array',
        'uses_ai' => 'boolean',
        'processes_sensitive_data' => 'boolean',
        'transfers_data_outside_nigeria' => 'boolean',
        'dpo_required' => 'boolean',
        'dpia_required' => 'boolean',
        'filing_deadline' => 'date',
        'car_generated_at' => 'datetime',
        'submitted_at' => 'datetime',
        'compliance_score' => 'decimal:2',
    ];

    public function documents(): HasMany
    {
        return $this->hasMany(GaidDocument::class);
    }

    public function obligations(): HasMany
    {
        return $this->hasMany(GaidObligation::class);
    }

    public function gaps(): HasMany
    {
        return $this->hasMany(GaidGap::class);
    }

    public function carDraft(): HasOne
    {
        return $this->hasOne(GaidCarDraft::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public static function generateReference(): string
    {
        $year = now()->year;
        $count = self::whereYear('created_at', $year)->count() + 1;

        return sprintf('GAID-%d-%04d', $year, $count);
    }
}
