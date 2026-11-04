<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DirectSignatureRecord extends Model
{
    protected $table = 'direct_signatures';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['signing_hotspots' => 'array', 'placement' => 'array'];
    }
}
