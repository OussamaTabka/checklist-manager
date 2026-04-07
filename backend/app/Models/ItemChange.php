<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemChange extends Model
{
    protected $fillable = [
        'version_item_id',
        'changed_by',
        'field_name',
        'old_value',
        'new_value',
        'change_type',
        'notes',
    ];

    public function versionItem(): BelongsTo
    {
        return $this->belongsTo(VersionItem::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /**
     * Get a human-readable description of the change
     */
    public function getDescriptionAttribute(): string
    {
        $field = str_replace('_', ' ', ucfirst($this->field_name));
        
        if ($this->change_type === 'created') {
            return "Item created by {$this->changedBy->name}";
        }
        
        if ($this->change_type === 'status_changed') {
            return "{$field} changed from '{$this->old_value}' to '{$this->new_value}'";
        }
        
        return "{$field} changed";
    }
}
