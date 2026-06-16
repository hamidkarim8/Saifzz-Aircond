<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\ClientUnit;
use App\Models\ServiceVisit;
use App\Models\User;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'serial_no',
        'name',
        'phone',
        'address',
        'tenant_id',
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

    /**
     * Restrict to clients in the user's tenant. Used by both the client registry and
     * the record-service picker, so it stays tenant-wide (a technician must be able to
     * service any tenant client). Per-technician "own clients" filtering for the
     * registry view lives in ClientController::index via scopeOwnedBy().
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($tid = $user->tenantId()) {
            $query->where('tenant_id', $tid);
        }

        return $query;
    }

    /**
     * Clients the technician has personally serviced (a visit assigned to them).
     * Scopes the registry to "their own clients" without affecting the service picker.
     */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->whereHas('visits', fn ($q) => $q->where('technician_id', $user->id));
    }
}
