<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceType extends Model
{
    protected $fillable = ['name', 'requires_next_service'];

    protected $casts = ['requires_next_service' => 'boolean'];
}
