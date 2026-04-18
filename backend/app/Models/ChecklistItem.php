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
        'order',
        'status',
        'last_run_at',
        'run_count',
    ];

    protected $casts = [
        'last_run_at' => 'datetime',
        'run_count' => 'integer',
    ];

    // Relation : item appartient à une checklist
    public function checklist()
    {
        return $this->belongsTo(Checklist::class);
    }
}