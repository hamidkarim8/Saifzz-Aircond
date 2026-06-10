<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'datetime',
        'service_type',
        'units',
        'address',
        'phone',
        'amount',
        'status',
        'contacted_flag',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'datetime' => 'datetime',
            'units' => 'integer',
            'amount' => 'decimal:2',
            'contacted_flag' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
