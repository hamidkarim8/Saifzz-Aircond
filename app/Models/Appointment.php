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
        'customer_name',
        'technician_id',
        'datetime',
        'address',
        'phone',
        'status',
        'contacted_flag',
        'notes',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'datetime' => 'datetime',
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
        // Tenant filter applies only when the user has a tenant; legacy/test
        // fixtures with null tenant are not filtered (see tenant seam contract).
        if ($tid = $user->tenantId()) {
            $query->where('tenant_id', $tid);
        }

        if (! $user->seesAllData()) {
            $query->where('technician_id', $user->id);
        }

        return $query;
    }
}
