<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Holding extends Model
{
    protected $table = 'holdings';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function whisky()
    {
        return $this->hasOne(WhiskyHolding::class);
    }

    public function wine()
    {
        return $this->hasOne(WineHolding::class);
    }

    public function agreement()
    {
        return $this->belongsTo(Deal::class, 'agreement_id');
    }

    public function scopeReleased($query)
    {
        return $query->whereNotNull('released_at')->whereHas('agreement', fn ($agreement) => $agreement
            ->whereNotNull('signed_document_id')->whereHas('signingRequests', fn ($signing) => $signing
            ->where('status', 'completed')->whereColumn('signing_requests.document_version_id', 'deals.document_id')));
    }
}
