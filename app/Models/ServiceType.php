<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceType extends Model
{
    public const MODES = ['flat', 'hp_tiered', 'flexible'];

    protected $fillable = ['name', 'pricing_mode', 'requires_next_service'];

    protected $casts = [
        'requires_next_service' => 'boolean',
    ];

    public function fees(): HasMany
    {
        return $this->hasMany(ServiceFee::class);
    }
}
