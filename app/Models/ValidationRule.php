<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ValidationRule extends Model
{
    protected $fillable = [
        'name',
        'code',
        'description',
        'category',
        'severity',
        'applies_to_type',
        'condition',
        'action',
        'is_active',
        'priority',
        'created_by',
    ];

    protected $casts = [
        'condition' => 'array',
        'is_active' => 'boolean',
    ];

    public function violations(): HasMany
    {
        return $this->hasMany(RuleViolation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
    public function scopeOrdered($q)
    {
        return $q->orderBy('priority');
    }
}
