<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChecklistItem extends Model
{
    protected $fillable = [
        'checklist_id',
        'title',
        'description',
        'priority',
        'criticality',
        'order',
        'status',
        'last_run_at',
        'run_count',
        'tested_by',
        'tested_at',
    ];

    protected $casts = [
        'status' => 'string',
        'last_run_at' => 'datetime',
        'run_count' => 'integer',
        'tested_at' => 'datetime',
    ];

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(Checklist::class);
    }

    public function tester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tested_by');
    }

    public function history(): HasMany
    {
        return $this->hasMany(ChecklistItemHistory::class, 'checklist_item_id')->orderByDesc('created_at');
    }

    public function testResults(): HasMany
    {
        return $this->hasMany(TestResult::class, 'checklist_item_id');
    }
}
