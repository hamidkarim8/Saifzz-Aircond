<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Transaction;
use App\Services\Documents\SnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SnapshotBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function transaction(): Transaction
    {
        $client = Client::create([
            'name' => 'Zainab', 'phone' => '012-3456789', 'address' => 'No. 5, Jalan Maju, KL',
        ]);
        $visit = $client->visits()->create([
            'visit_date' => '2026-06-08', 'warranty_months' => 3, 'total_amount' => 110,
        ]);
        $visit->lines()->create([
            'service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted',
            'units' => 2, 'rate' => 60, 'discount' => 10, 'next_service_date' => '2026-09-05',
        ]);

        return $visit->transaction()->create([
            'txn_id' => 'TXN-20260608-001', 'amount' => 110,
            'method' => 'DuitNow QR', 'status' => 'pending',
        ]);
    }

    public function test_snapshot_includes_warranty_next_service_and_business(): void
    {
        $snapshot = (new SnapshotBuilder)->forTransaction($this->transaction());

        $this->assertSame(3, $snapshot['warranty_months']);
        $this->assertSame('2026-09-05', $snapshot['lines'][0]['next_service_date']);
        $this->assertSame(config('business.name'), $snapshot['business']['name']);
        $this->assertSame('Zainab', $snapshot['client']['name']);
        $this->assertSame('110.00', (string) $snapshot['total_amount']);
    }
}
