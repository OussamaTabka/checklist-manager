<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserStory extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'project_id',
        'title',
        'description',
        'as_a',
        'i_want_that',
        'so_that',
        'acceptance_criteria',
        'business_rules',
        'scenarios',
        'effort_points',
        'business_value',
        'start_date',
        'target_completion_date',
        'status',
        'priority',
        'story_id',
        'created_by',
    ];

    protected $casts = [
        'business_rules' => 'array',
        'scenarios' => 'array',
        'start_date' => 'date',
        'target_completion_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the project this user story belongs to
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the user who created this story
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get all checklists associated with this user story
     */
    public function checklists(): BelongsToMany
    {
        return $this->belongsToMany(
            Checklist::class,
            'user_story_checklists',
            'user_story_id',
            'checklist_id'
        )->withPivot(['is_generated_from_arxis', 'relevance_score', 'link_type'])->withTimestamps();
    }
}
