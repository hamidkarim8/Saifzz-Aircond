<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Documents\SnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentContentTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
            'permissions' => ['view_clients'],
        ]);
    }

    /**
     * A visit with two HP-based Installation lines at different HP ratings and
     * different discounts — the exact shape Khalid reported as unreadable.
     */
    private function hpTransaction(?int $technicianId = null): Transaction
    {
        $client = Client::create([
            'name' => 'Zainab',
            'phone' => '012-3456789',
            'address' => 'No. 5, Jalan Maju, KL',
        ]);

        $visit = $client->visits()->create([
            'visit_date' => '2026-07-12',
            'warranty_months' => 3,
            'total_amount' => 570,
            'technician_id' => $technicianId,
        ]);

        $visit->lines()->create([
            'service_type' => 'Installation', 'unit_type' => 'Wall Mounted',
            'units' => 1, 'rate' => 370, 'discount' => 100, 'hp_value' => 1.0,
            'next_service_date' => '2026-10-12',
        ]);
        $visit->lines()->create([
            'service_type' => 'Installation', 'unit_type' => 'Wall Mounted',
            'units' => 1, 'rate' => 400, 'discount' => 100, 'hp_value' => 1.5,
            'next_service_date' => '2026-10-12',
        ]);

        return $visit->transaction()->create([
            'txn_id' => 'TXN-20260712-001', 'amount' => 570,
            'method' => 'DuitNow QR', 'status' => 'pending',
        ]);
    }

    public function test_snapshot_captures_hp_value_per_line(): void
    {
        $txn = $this->hpTransaction();

        $snapshot = app(SnapshotBuilder::class)->forTransaction($txn);

        $this->assertCount(2, $snapshot['lines']);
        $this->assertEquals(1.0, (float) $snapshot['lines'][0]['hp_value']);
        $this->assertEquals(1.5, (float) $snapshot['lines'][1]['hp_value']);
    }

    public function test_snapshot_hp_value_is_null_for_non_hp_line(): void
    {
        $client = Client::create(['name' => 'Ali', 'phone' => '011-1', 'address' => 'KL']);
        $visit = $client->visits()->create([
            'visit_date' => '2026-07-12', 'warranty_months' => 0, 'total_amount' => 60,
        ]);
        $visit->lines()->create([
            'service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted',
            'units' => 1, 'rate' => 60, 'discount' => 0,
        ]);
        $txn = $visit->transaction()->create([
            'txn_id' => 'TXN-20260712-002', 'amount' => 60,
            'method' => 'Cash', 'status' => 'pending',
        ]);

        $snapshot = app(SnapshotBuilder::class)->forTransaction($txn);

        $this->assertArrayHasKey('hp_value', $snapshot['lines'][0]);
        $this->assertNull($snapshot['lines'][0]['hp_value']);
    }
}
