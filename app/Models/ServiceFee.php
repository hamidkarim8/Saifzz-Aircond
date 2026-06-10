<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceFee extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_type',
        'option',
        'rate',
        'pricing_mode',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
        ];
    }
}
