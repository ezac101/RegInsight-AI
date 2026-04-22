<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GaidCarDraft extends Model
{
    protected $fillable = [
        'gaid_submission_id',
        'file_path',
        'car_data',
        'ndpc_template_version',
        'compliance_score',
        'status',
        'downloaded_at',
    ];

    protected $casts = [
        'car_data' => 'array',
        'compliance_score' => 'decimal:2',
        'downloaded_at' => 'datetime',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(GaidSubmission::class, 'gaid_submission_id');
    }
}
