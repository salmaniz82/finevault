<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Deal extends Model
{
    protected $table = 'deals';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['agreement_snapshot' => 'array', 'stock_minor' => 'integer', 'total_minor' => 'integer', 'balance_minor' => 'integer'];
    }

    public function items()
    {
        return $this->hasMany(DealItem::class)->orderBy('position');
    }

    public function fees()
    {
        return $this->hasMany(DealFee::class);
    }

    public function deposits()
    {
        return $this->hasMany(ExternalDeposit::class)->orderBy('position');
    }

    public function signingRequests()
    {
        return $this->hasMany(SigningRequest::class);
    }
}
