<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceHpTier extends Model
{
    protected $fillable = ['service_type_id', 'hp_value', 'price'];

    protected $casts = [
        'hp_value' => 'decimal:1',
        'price'    => 'decimal:2',
    ];

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }
}
