<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\ClientUnit;
use App\Models\ServiceVisit;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'serial_no',
        'name',
        'phone',
        'address',
    ];

    /**
     * R6 — assign a 6-digit, zero-padded, monotonic serial on create if none provided.
     */
    protected static function booted(): void
    {
        static::creating(function (Client $client) {
            if (empty($client->serial_no)) {
                $max = (int) static::withTrashed()->max('serial_no');
                $client->serial_no = str_pad((string) ($max + 1), 6, '0', STR_PAD_LEFT);
            }
        });
    }

    public function visits(): HasMany
    {
        return $this->hasMany(ServiceVisit::class);
    }

    public function latestVisit(): HasOne
    {
        return $this->hasOne(ServiceVisit::class)->latestOfMany('visit_date');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function reminderContact(): HasOne
    {
        return $this->hasOne(ReminderContact::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(ClientUnit::class);
    }
}
