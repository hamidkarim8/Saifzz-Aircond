<?php

namespace Tests\Feature;

use App\Models\PermissionPreset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PermissionPresetTest extends TestCase
{
    use RefreshDatabase;

    /** Create a boss admin that is its own tenant root. */
    private function boss(): User
    {
        $boss = User::factory()->admin()->create();
        $boss->update(['tenant_id' => $boss->id]);

        return $boss->fresh();
    }

    public function test_permission_presets_table_exists(): void
    {
        $this->assertTrue(Schema::hasColumn('permission_presets', 'tenant_id'));
        $this->assertTrue(Schema::hasColumn('permission_presets', 'level'));
        $this->assertTrue(Schema::hasColumn('permission_presets', 'permissions'));
    }

    public function test_for_tenant_returns_defaults_when_no_rows(): void
    {
        $boss = $this->boss();

        $presets = PermissionPreset::forTenant($boss->id);

        $this->assertSame(PermissionPreset::DEFAULTS[1], $presets[1]);
        $this->assertSame(PermissionPreset::DEFAULTS[2], $presets[2]);
        $this->assertSame(PermissionPreset::DEFAULTS[3], $presets[3]);
    }

    public function test_for_tenant_returns_saved_rows_over_defaults(): void
    {
        $boss = $this->boss();
        PermissionPreset::create([
            'tenant_id' => $boss->id,
            'level' => 1,
            'permissions' => ['record_service'],
        ]);

        $presets = PermissionPreset::forTenant($boss->id);

        $this->assertSame(['record_service'], $presets[1]);
        // levels without a row still fall back to defaults
        $this->assertSame(PermissionPreset::DEFAULTS[2], $presets[2]);
    }

    public function test_for_tenant_strips_manage_users(): void
    {
        $boss = $this->boss();
        PermissionPreset::create([
            'tenant_id' => $boss->id,
            'level' => 3,
            'permissions' => ['view_reports', 'manage_users'],
        ]);

        $this->assertSame(['view_reports'], PermissionPreset::forTenant($boss->id)[3]);
    }
}
