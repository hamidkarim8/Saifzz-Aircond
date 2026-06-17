<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_for_tenant_returns_row_when_present(): void
    {
        $boss = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $boss->update(['tenant_id' => $boss->id]);
        BusinessSetting::create([
            'tenant_id' => $boss->id,
            'business_name' => 'Acme Cooling',
            'ssm_no' => '202603093151 (003839732-K)',
        ]);

        $resolved = BusinessSetting::forTenant($boss->id);

        $this->assertSame('Acme Cooling', $resolved['name']);
        $this->assertSame('202603093151 (003839732-K)', $resolved['ssm_no']);
    }

    public function test_for_tenant_falls_back_to_config_when_absent(): void
    {
        $resolved = BusinessSetting::forTenant(null);

        $this->assertSame(config('business.name'), $resolved['name']);
        $this->assertNull($resolved['ssm_no']);
    }
}
