<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalDeposit extends Model
{
    protected $table = 'external_deposits';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }
}
