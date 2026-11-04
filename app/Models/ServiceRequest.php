<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceRequest extends Model
{
    protected $table = 'service_requests';

    protected $guarded = [];

    public $timestamps = false;

    public function events()
    {
        return $this->hasMany(RequestEvent::class, 'request_id')->orderBy('position');
    }

    public function holding()
    {
        return $this->belongsTo(Holding::class);
    }
}
