<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DealItem extends Model
{
    protected $table = 'deal_items';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price_minor' => 'integer', 'cask_size' => 'float', 'ola' => 'float', 'rla' => 'float', 'abv' => 'float', 'vintage' => 'integer', 'bottle_ml' => 'integer'];
    }
}
