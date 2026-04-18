<?php

namespace App\Models;
use App\Models\ChecklistItem;
use App\Models\User;
use App\Models\UserStory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Checklist extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'category',
        'is_active',
        'created_by'
    ];

    // Relation : une checklist a plusieurs items
    public function items()
    {
        return $this->hasMany(ChecklistItem::class);
    }

    // Relation : checklist créée par un user
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get all user stories associated with this checklist
     */
    public function userStories(): BelongsToMany
    {
        return $this->belongsToMany(
            UserStory::class,
            'user_story_checklists',
            'checklist_id',
            'user_story_id'
        )->withPivot('is_generated_from_arxis')->withTimestamps();
    }
}