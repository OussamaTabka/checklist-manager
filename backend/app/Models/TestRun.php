<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TestRun extends Model
{
    protected $fillable = [
        'run_id',
        'project_version_id',
        'checklist_id',
        'schema_version',
        'base_url',
        'mode',
        'status',
        'requested_by',
        'started_at',
        'finished_at',
        'summary_total',
        'summary_passed',
        'summary_failed',
        'summary_blocked',
        'summary_skipped',
        'request_payload',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'request_payload' => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'run_id';
    }

    public function projectVersion(): BelongsTo
    {
        return $this->belongsTo(ProjectVersion::class);
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(Checklist::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function results(): HasMany
    {
        return $this->hasMany(TestResult::class);
    }
}
