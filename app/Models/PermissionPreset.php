<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PermissionPreset extends Model
{
    protected $fillable = ['tenant_id', 'level', 'permissions'];

    protected function casts(): array
    {
        return ['permissions' => 'array'];
    }

    /**
     * Hardcoded baseline used when a tenant has not customised a level.
     * Admin-editable defaults (CHG-011). manage_users is never included.
     */
    public const DEFAULTS = [
        1 => ['view_clients', 'record_service', 'set_appointment', 'manage_service_types', 'manage_units'],
        2 => ['view_clients', 'record_service', 'set_appointment', 'manage_service_types', 'manage_units', 'collect_payment', 'edit_client'],
        3 => ['view_clients', 'record_service', 'set_appointment', 'manage_service_types', 'manage_units', 'collect_payment', 'edit_client', 'view_all_data', 'view_reports', 'export_data'],
    ];

    /**
     * Resolve the three level baselines for a tenant: saved rows when present,
     * otherwise DEFAULTS. manage_users is defensively stripped from every level.
     *
     * @return array<int, array<int, string>> keyed 1,2,3
     */
    public static function forTenant(?int $tenantId): array
    {
        $saved = $tenantId === null
            ? collect()
            : static::where('tenant_id', $tenantId)->get()->keyBy('level');

        $out = [];
        foreach ([1, 2, 3] as $level) {
            $perms = $saved->has($level)
                ? $saved->get($level)->permissions
                : self::DEFAULTS[$level];

            $out[$level] = array_values(array_filter(
                $perms,
                fn ($p) => ! in_array($p, User::ADMIN_ONLY_PERMISSIONS, true),
            ));
        }

        return $out;
    }
}
