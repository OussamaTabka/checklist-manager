<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChecklistItem extends Model
{
    protected $fillable = [
        'checklist_id',
        'title',
        'description',
        'priority',
        'criticality',
        'order'
    ];

    // Relation : item appartient à une checklist
    public function checklist()
    {
        return $this->belongsTo(Checklist::class);
    }
}