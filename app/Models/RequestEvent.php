<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequestEvent extends Model
{
    protected $table = 'request_events';

    protected $guarded = [];

    public $timestamps = false;
}
