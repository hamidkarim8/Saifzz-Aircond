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

    public function test_admin_saves_presets_for_own_tenant(): void
    {
        $boss = $this->boss();

        $this->actingAs($boss)->put(route('permission-presets.update'), [
            'presets' => [
                1 => ['record_service'],
                2 => ['record_service', 'collect_payment'],
                3 => ['record_service', 'collect_payment', 'view_reports'],
            ],
        ])->assertRedirect();

        $this->assertSame(['record_service'], PermissionPreset::forTenant($boss->id)[1]);
        $this->assertSame(3, PermissionPreset::where('tenant_id', $boss->id)->count());
    }

    public function test_save_is_idempotent_upsert(): void
    {
        $boss = $this->boss();
        $payload = ['presets' => [1 => ['record_service'], 2 => [], 3 => []]];

        $this->actingAs($boss)->put(route('permission-presets.update'), $payload)->assertRedirect();
        $this->actingAs($boss)->put(route('permission-presets.update'), $payload)->assertRedirect();

        $this->assertSame(3, PermissionPreset::where('tenant_id', $boss->id)->count());
    }

    public function test_save_rejects_manage_users_and_unknown_keys(): void
    {
        $boss = $this->boss();

        $this->actingAs($boss)->put(route('permission-presets.update'), [
            'presets' => [1 => ['manage_users'], 2 => [], 3 => []],
        ])->assertSessionHasErrors('presets.1.0');

        $this->actingAs($boss)->put(route('permission-presets.update'), [
            'presets' => [1 => ['bogus_perm'], 2 => [], 3 => []],
        ])->assertSessionHasErrors('presets.1.0');
    }

    public function test_presets_are_tenant_isolated(): void
    {
        $khalid = $this->boss();
        $saifzz = $this->boss();

        $this->actingAs($khalid)->put(route('permission-presets.update'), [
            'presets' => [1 => ['record_service'], 2 => [], 3 => []],
        ])->assertRedirect();

        // Saifzz sees his own defaults, not Khalid's saved L1.
        $this->assertSame(PermissionPreset::DEFAULTS[1], PermissionPreset::forTenant($saifzz->id)[1]);
        $this->assertSame(0, PermissionPreset::where('tenant_id', $saifzz->id)->count());
    }

    public function test_technician_cannot_save_presets(): void
    {
        $boss = $this->boss();
        $tech = User::factory()->create(['tenant_id' => $boss->id, 'role' => User::ROLE_TECHNICIAN]);

        $this->actingAs($tech)->put(route('permission-presets.update'), [
            'presets' => [1 => [], 2 => [], 3 => []],
        ])->assertForbidden();
    }
}
