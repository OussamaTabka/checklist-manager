<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'description', 'test_objectives', 'app_url', 'created_by', 'start_date', 'status'];

    public function versions(): HasMany
    {
        return $this->hasMany(ProjectVersion::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get all testers assigned to this project
     */
    public function testers(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'project_testers',
            'project_id',
            'user_id'
        )->withTimestamps();
    }

    /**
     * Get all checklists assigned to this project
     */
    public function checklists(): BelongsToMany
    {
        return $this->belongsToMany(
            Checklist::class,
            'project_checklists',
            'project_id',
            'checklist_id'
        )->withTimestamps();
    }

    /**
     * Get all user stories for this project
     */
    public function userStories(): HasMany
    {
        return $this->hasMany(UserStory::class);
    }
}
