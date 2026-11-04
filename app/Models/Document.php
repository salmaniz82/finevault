<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $table = 'documents';

    protected $guarded = [];

    public $timestamps = false;

    public function versions()
    {
        return $this->hasMany(DocumentVersion::class);
    }

    public function type()
    {
        return $this->belongsTo(DocumentType::class, 'category', 'code');
    }
}
