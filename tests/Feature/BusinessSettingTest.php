<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Client;
use App\Models\ServiceVisit;
use App\Models\Transaction;
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

    public function test_snapshot_freezes_per_tenant_business_identity(): void
    {
        $boss = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $boss->update(['tenant_id' => $boss->id]);
        BusinessSetting::create([
            'tenant_id' => $boss->id,
            'business_name' => 'Tenant Cooling Co',
            'ssm_no' => 'SSM-123',
        ]);

        $client = Client::create([
            'tenant_id' => $boss->id,
            'name' => 'Test Client',
            'phone' => '0123456789',
            'address' => '123 Test Street',
        ]);
        $visit = ServiceVisit::create([
            'tenant_id' => $boss->id,
            'client_id' => $client->id,
            'visit_date' => now()->toDateString(),
            'created_by' => $boss->id,
            'technician_id' => $boss->id,
        ]);
        $txn = Transaction::create([
            'visit_id' => $visit->id,
            'txn_id' => 'TXN-' . now()->format('Ymd') . '-001',
            'amount' => '0.00',
            'method' => 'Cash',
            'status' => 'pending',
        ]);

        $snap = app(\App\Services\Documents\SnapshotBuilder::class)->forTransaction($txn);

        $this->assertSame('Tenant Cooling Co', $snap['business']['name']);
        $this->assertSame('SSM-123', $snap['business']['ssm_no']);
    }
}
