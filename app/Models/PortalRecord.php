<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['client_id', 'kind', 'data'])]
class PortalRecord extends Model
{
    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
