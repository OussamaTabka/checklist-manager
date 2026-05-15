<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VersionItem extends Model
{
    protected $fillable = [
        'project_version_id',
        'title','description','priority','criticality','order',
        'status','tested_by','tested_at',
        'execution_profile',
    ];

    protected $casts = [
        'tested_at' => 'datetime',
        'execution_profile' => 'array',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(ProjectVersion::class, 'project_version_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function changes(): HasMany
    {
        return $this->hasMany(ItemChange::class)->orderByDesc('created_at');
    }

    public function tester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tested_by');
    }

    public function testResults(): HasMany
    {
        return $this->hasMany(TestResult::class);
    }
}
