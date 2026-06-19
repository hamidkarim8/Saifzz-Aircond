<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessSetting extends Model
{
    protected $fillable = [
        'tenant_id', 'business_name', 'address', 'phone',
        'ssm_no', 'google_review_url', 'google_review_qr_path',
        'payment_qr_path',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    /**
     * Resolve a tenant's business identity, falling back to global config
     * when no row exists (or tenant is null — test fixtures / legacy rows).
     *
     * @return array{name:string,address:string,phone:string,ssm_no:?string,google_review_url:?string,google_review_qr_path:?string,payment_qr_path:?string}
     */
    public static function forTenant(?int $tenantId): array
    {
        $row = $tenantId !== null
            ? static::where('tenant_id', $tenantId)->first()
            : null;

        return [
            'name' => $row?->business_name ?: config('business.name'),
            'address' => $row?->address ?: config('business.address'),
            'phone' => $row?->phone ?: config('business.phone'),
            'ssm_no' => $row?->ssm_no,
            'google_review_url' => $row?->google_review_url,
            'google_review_qr_path' => $row?->google_review_qr_path,
            'payment_qr_path' => $row?->payment_qr_path,
        ];
    }
}
