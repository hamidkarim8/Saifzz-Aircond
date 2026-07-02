<?php

namespace Tests\Feature\Portal;

use App\Models\Client;
use App\Services\Portal\PortalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): PortalService
    {
        return app(PortalService::class);
    }

    private function client(string $phone = '012-345 6789'): Client
    {
        return Client::create(['name' => 'Zainab', 'phone' => $phone, 'address' => 'No. 5, KL']);
    }

    public function test_authenticate_matches_serial_and_phone_last4(): void
    {
        $client = $this->client();

        $match = $this->service()->authenticate($client->serial_no, '6789');

        $this->assertNotNull($match);
        $this->assertTrue($match->is($client));
    }

    public function test_authenticate_rejects_wrong_phone4(): void
    {
        $client = $this->client();

        $this->assertNull($this->service()->authenticate($client->serial_no, '0000'));
    }

    public function test_authenticate_rejects_unknown_serial(): void
    {
        $this->client();

        $this->assertNull($this->service()->authenticate('999999', '6789'));
    }

    public function test_authenticate_ignores_phone_formatting(): void
    {
        $client = $this->client('012-345 6789');

        $this->assertNotNull($this->service()->authenticate($client->serial_no, '6789'));
    }

    public function test_account_next_service_is_max_ignoring_nulls(): void
    {
        $client = $this->client();
        $v1 = $client->visits()->create(['visit_date' => '2026-01-10', 'warranty_months' => 3, 'total_amount' => 60]);
        $v1->lines()->create(['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 1, 'rate' => 60, 'discount' => 0, 'next_service_date' => '2026-07-10']);
        $v1->transaction()->create(['txn_id' => 'TXN-1', 'amount' => 60, 'method' => 'Cash', 'status' => 'paid']);
        $v2 = $client->visits()->create(['visit_date' => '2026-03-01', 'warranty_months' => 0, 'total_amount' => 80]);
        $v2->lines()->create(['service_type' => 'Repair', 'repair_desc' => 'Fan motor', 'units' => 1, 'rate' => 80, 'discount' => 0, 'next_service_date' => null]);
        $v2->transaction()->create(['txn_id' => 'TXN-2', 'amount' => 80, 'method' => 'Cash', 'status' => 'paid']);

        $account = $this->service()->accountFor($client->fresh());

        $this->assertSame('2026-07-10', $account['next_service_date']);
        $this->assertCount(2, $account['visits']);
        $this->assertSame('000001', $account['client']['serial_no']);
    }

    public function test_account_excludes_void_and_cancelled_visits(): void
    {
        $client = $this->client();

        $paid = $client->visits()->create(['visit_date' => '2026-01-10', 'warranty_months' => 0, 'total_amount' => 60]);
        $paid->transaction()->create(['txn_id' => 'TXN-A', 'amount' => 60, 'method' => 'Cash', 'status' => 'paid']);

        $void = $client->visits()->create(['visit_date' => '2026-02-10', 'warranty_months' => 0, 'total_amount' => 60]);
        $void->transaction()->create(['txn_id' => 'TXN-B', 'amount' => 60, 'method' => 'Cash', 'status' => 'void']);

        $cancelled = $client->visits()->create(['visit_date' => '2026-03-10', 'warranty_months' => 0, 'total_amount' => 60]);
        $cancelled->transaction()->create(['txn_id' => 'TXN-C', 'amount' => 60, 'method' => 'Cash', 'status' => 'cancelled']);

        $account = $this->service()->accountFor($client->fresh());

        $this->assertCount(1, $account['visits']);
        $this->assertSame($paid->id, $account['visits'][0]['id']);
    }
}
