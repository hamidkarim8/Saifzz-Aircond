<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceType extends Model
{
    protected $fillable = ['name', 'requires_next_service', 'is_hp_based'];

    protected $casts = [
        'requires_next_service' => 'boolean',
        'is_hp_based'           => 'boolean',
    ];

    public function hpTiers(): HasMany
    {
        return $this->hasMany(ServiceHpTier::class);
    }
}
