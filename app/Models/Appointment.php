<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Appointment extends Model
{
    use HasFactory;

    /** Service types an appointment can be booked for (mirrors the fee book). */
    public const SERVICE_TYPES = ['Cleaning', 'Gas Top-Up', 'Repair', 'Installation', 'Troubleshoot'];

    /** Status lifecycle (docs/04 §7): pending → confirmed → done / cancelled. */
    public const STATUSES = ['pending', 'confirmed', 'done', 'cancelled'];

    /** Allowed forward transitions per state; done/cancelled are terminal. */
    public const TRANSITIONS = [
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['done', 'cancelled'],
        'done' => [],
        'cancelled' => [],
    ];

    protected $fillable = [
        'client_id',
        'technician_id',
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
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    /**
     * Is moving to $status legal from the current state?
     */
    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * Limit to appointments whose datetime falls within the given 'YYYY-MM' month.
     */
    public function scopeForMonth(Builder $query, string $month): Builder
    {
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();

        return $query->whereBetween('datetime', [$start, $start->copy()->endOfMonth()]);
    }

    /**
     * Restrict to appointments the user may see. Unassigned (null technician) are visible
     * only to all-data users; scoped technicians see only appointments assigned to them.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->seesAllData() ? $query : $query->where('technician_id', $user->id);
    }
}
