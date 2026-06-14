<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ServiceVisit extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'visit_date',
        'warranty_months',
        'warranty_end',
        'total_amount',
        'created_by',
        'technician_id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'visit_date' => 'date',
            'warranty_end' => 'date',
            'warranty_months' => 'integer',
            'total_amount' => 'decimal:2',
        ];
    }

    /**
     * R5 — derive warranty_end from visit_date + warranty_months (null when 0).
     */
    protected static function booted(): void
    {
        static::saving(function (ServiceVisit $visit) {
            $visit->warranty_end = $visit->warranty_months > 0
                ? $visit->visit_date?->copy()->addMonths($visit->warranty_months)
                : null;
        });
    }

    /**
     * R8 — recompute total from line subtotals. Call after lines change.
     */
    public function recalculateTotal(): void
    {
        $this->total_amount = $this->lines()->sum('subtotal');
        $this->save();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ServiceLine::class, 'visit_id');
    }

    public function transaction(): HasOne
    {
        return $this->hasOne(Transaction::class, 'visit_id');
    }

    /**
     * Restrict to rows the user may see. All-data users (admins + view_all_data) see everything;
     * scoped technicians see only visits assigned to them.
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
