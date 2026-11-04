<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientProfile extends Model
{
    protected $table = 'client_profiles';

    protected $guarded = [];

    public $timestamps = true;

    protected $primaryKey = 'user_id';

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function managers()
    {
        return $this->belongsToMany(User::class, 'client_manager_assignments', 'client_id', 'manager_id', 'user_id')->withTimestamps();
    }
}
