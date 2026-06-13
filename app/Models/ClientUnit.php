<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientUnit extends Model
{
    protected $fillable = [
        'client_id', 'label', 'unit_type', 'hp', 'brand', 'model',
        'serial_no', 'refrigerant_type', 'next_service_date', 'next_service_type',
        'is_active', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'hp' => 'decimal:2',
            'next_service_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ServiceLine::class, 'unit_id');
    }

    public function scopeActive($query): void
    {
        $query->where('is_active', true);
    }
}
