<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceFee extends Model
{
    use HasFactory;

    protected $fillable = ['service_type_id', 'unit_type', 'hp_value', 'price'];

    protected function casts(): array
    {
        return [
            'hp_value' => 'decimal:1',
            'price'    => 'decimal:2',
        ];
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }
}
