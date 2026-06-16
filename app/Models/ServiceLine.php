<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'visit_id',
        'unit_id',
        'service_type',
        'unit_type',
        'gas_option',
        'units',
        'rate',
        'repair_desc',
        'discount',
        'hp_value',
        'next_service_date',
        'notes',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'units' => 'integer',
            'rate' => 'decimal:2',
            'discount' => 'decimal:2',
            'hp_value' => 'decimal:1',
            'subtotal' => 'decimal:2',
            'next_service_date' => 'date',
        ];
    }

    /**
     * R8 — subtotal = max(0, rate * units - discount), floored at 0.
     */
    protected static function booted(): void
    {
        static::saving(function (ServiceLine $line) {
            $line->subtotal = max(0, ((float) $line->rate * (int) $line->units) - (float) $line->discount);
        });
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(ServiceVisit::class, 'visit_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ClientUnit::class, 'unit_id');
    }
}
