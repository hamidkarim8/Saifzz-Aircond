<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'permissions', 'active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_TECHNICIAN = 'technician';

    /** Reserved to admins; never grantable to technicians (P1). */
    public const ADMIN_ONLY_PERMISSIONS = ['manage_users'];

    /** Full permission catalogue (docs/03). */
    public const PERMISSIONS = [
        'view_clients',
        'record_service',
        'set_appointment',
        'collect_payment',
        'edit_client',
        'view_reports',
        'edit_fees',
        'export_data',
        'view_all_data',
        'manage_users',
        'manage_service_types',
    ];

    /** A new technician starts with exactly these. */
    public const DEFAULT_TECHNICIAN_PERMISSIONS = [
        'view_clients',
        'record_service',
        'set_appointment',
        'manage_service_types',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'permissions' => 'array',
            'active' => 'boolean',
        ];
    }

    /**
     * New technicians default to the minimum permission set.
     */
    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if ($user->role !== self::ROLE_ADMIN && $user->permissions === null) {
                $user->permissions = self::DEFAULT_TECHNICIAN_PERMISSIONS;
            }
        });
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * Server-side permission check (P3). Admins implicitly hold every permission.
     * `manage_users` is admin-only (P1) regardless of granted list.
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        if (in_array($permission, self::ADMIN_ONLY_PERMISSIONS, true)) {
            return false;
        }

        return in_array($permission, $this->permissions ?? [], true);
    }

    /**
     * True when the user sees every row (no per-technician scoping).
     * Admins short-circuit to true via hasPermission().
     */
    public function seesAllData(): bool
    {
        return $this->hasPermission('view_all_data');
    }

    /**
     * Grant a permission to a technician. Rejects admin-only permissions (P1).
     */
    public function grantPermission(string $permission): void
    {
        if (in_array($permission, self::ADMIN_ONLY_PERMISSIONS, true)) {
            return; // P1 — never grantable
        }

        if (! in_array($permission, self::PERMISSIONS, true)) {
            return; // unknown permission
        }

        $current = $this->permissions ?? [];

        if (! in_array($permission, $current, true)) {
            $current[] = $permission;
            $this->permissions = array_values($current);
        }
    }

    public function revokePermission(string $permission): void
    {
        $this->permissions = array_values(
            array_filter($this->permissions ?? [], fn ($p) => $p !== $permission)
        );
    }
}
