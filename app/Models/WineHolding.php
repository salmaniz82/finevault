<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WineHolding extends Model
{
    protected $table = 'wine_holdings';

    protected $guarded = [];

    public $timestamps = false;

    protected $primaryKey = 'holding_id';

    protected function casts(): array
    {
        return ['vintage' => 'integer', 'bottle_ml' => 'float', 'bottles_per_case' => 'integer'];
    }
}
