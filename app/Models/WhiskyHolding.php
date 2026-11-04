<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhiskyHolding extends Model
{
    protected $table = 'whisky_holdings';

    protected $guarded = [];

    public $timestamps = false;

    protected $primaryKey = 'holding_id';

    protected function casts(): array
    {
        return ['abv' => 'float', 'litres' => 'float'];
    }
}
