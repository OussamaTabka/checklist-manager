<?php

namespace App\Models;
use App\Models\ChecklistItem;
use App\Models\User;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Checklist extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'description',
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
}