<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VersionItem extends Model
{
    protected $fillable = [
        'project_version_id',
        'title','description','priority','criticality','order',
        'status','tested_by','tested_at'
    ];

    public function version()
    {
        return $this->belongsTo(ProjectVersion::class, 'project_version_id');
    }
}