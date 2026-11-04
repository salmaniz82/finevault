<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DealFee extends Model
{
    protected $table = 'deal_fees';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['rate' => 'float', 'amount_minor' => 'integer'];
    }
}
