<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    use HasUuids;

    protected $table = 'notifications';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'notifiable_type',
        'notifiable_id',
        'data',
        'user_id',
        'role',
        'type',
        'title',
        'message',
        'priority',
        'link',
        'target_type',
        'target_id',
        'project_id',
        'version_id',
        'checklist_id',
        'test_case_id',
        'comment_id',
        'section',
        'is_read',
        'is_archived',
        'read_at',
    ];

    protected $casts = [
        'target_id' => 'integer',
        'project_id' => 'integer',
        'version_id' => 'integer',
        'checklist_id' => 'integer',
        'test_case_id' => 'integer',
        'comment_id' => 'integer',
        'is_read' => 'boolean',
        'is_archived' => 'boolean',
        'read_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'data' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ProjectVersion::class, 'version_id');
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(Checklist::class);
    }

    public function testCase(): BelongsTo
    {
        return $this->belongsTo(VersionItem::class, 'test_case_id');
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }
}
