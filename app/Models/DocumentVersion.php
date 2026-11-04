<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentVersion extends Model
{
    protected $table = 'document_versions';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['signing_hotspots' => 'array'];
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function reviews()
    {
        return $this->hasMany(DocumentReview::class)->orderBy('id');
    }

    public function holding()
    {
        return $this->belongsTo(Holding::class);
    }

    public function deal()
    {
        return $this->belongsTo(Deal::class);
    }
}
