<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProjectVersion extends Model
{
    use SoftDeletes;

    protected $fillable = ['project_id', 'checklist_id', 'version_number'];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function checklist()
    {
        return $this->belongsTo(Checklist::class);
    }

    public function items()
    {
        return $this->hasMany(VersionItem::class);
    }
}