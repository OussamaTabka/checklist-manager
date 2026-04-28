<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestResult extends Model
{
    protected $fillable = [
        'test_run_id',
        'version_item_id',
        'checklist_item_id',
        'status',
        'error_type',
        'error_message',
        'duration_ms',
        'artifacts',
        'result_payload',
        'executed_at',
    ];

    protected $casts = [
        'duration_ms' => 'integer',
        'artifacts' => 'array',
        'result_payload' => 'array',
        'executed_at' => 'datetime',
    ];

    public function testRun(): BelongsTo
    {
        return $this->belongsTo(TestRun::class);
    }

    public function versionItem(): BelongsTo
    {
        return $this->belongsTo(VersionItem::class);
    }

    public function checklistItem(): BelongsTo
    {
        return $this->belongsTo(ChecklistItem::class);
    }
}
