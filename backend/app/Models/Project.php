<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'description', 'created_by'];

    public function versions()
    {
        return $this->hasMany(ProjectVersion::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}