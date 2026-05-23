<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChecklistItemHistory extends Model
{
    protected $fillable = [
        'checklist_item_id',
        'changed_by',
        'field_name',
        'old_value',
        'new_value',
        'change_type',
        'notes',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the checklist item this history belongs to
     */
    public function checklistItem(): BelongsTo
    {
        return $this->belongsTo(ChecklistItem::class, 'checklist_item_id');
    }

    /**
     * Get the user who made this change
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /**
     * Scope to get all status changes
     */
    public function scopeStatusChanges($query)
    {
        return $query->where('field_name', 'status');
    }

    /**
     * Scope to get changes ordered by date descending
     */
    public function scopeLatest($query)
    {
        return $query->orderBy('created_at', 'desc');
    }
}
