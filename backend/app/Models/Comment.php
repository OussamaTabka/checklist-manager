<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    protected $fillable = [
        'version_item_id',
        'user_id',
        'content',
        'file_path',
        'file_name',
        'file_size'
    ];

    // Relation : un commentaire appartient à un version_item
    public function versionItem()
    {
        return $this->belongsTo(VersionItem::class);
    }

    // Relation : un commentaire est écrit par un user
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
