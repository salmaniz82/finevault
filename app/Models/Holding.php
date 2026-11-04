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
}
