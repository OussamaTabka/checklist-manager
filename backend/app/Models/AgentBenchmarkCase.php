<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentBenchmarkCase extends Model
{
    protected $fillable = [
        'project_id',
        'checklist_id',
        'checklist_item_id',
        'source_app',
        'project_url',
        'expected_scenario_type',
        'provided_inputs',
        'expected_result',
        'test_kind',
        'enabled',
    ];

    protected $casts = [
        'provided_inputs' => 'array',
        'expected_result' => 'array',
        'enabled' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(Checklist::class);
    }

    public function checklistItem(): BelongsTo
    {
        return $this->belongsTo(ChecklistItem::class);
    }
}
