<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SigningRequest extends Model
{
    protected $table = 'signing_requests';

    protected $guarded = [];

    public $timestamps = true;
}
