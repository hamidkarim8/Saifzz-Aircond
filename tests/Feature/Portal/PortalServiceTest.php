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

    public function test_account_next_service_is_nearest_future_ignoring_nulls_and_past(): void
    {
        $client = $this->client();

        // Past — a lapsed/unfulfilled date must not surface as "next".
        $vPast = $client->visits()->create(['visit_date' => '2026-01-10', 'warranty_months' => 3, 'total_amount' => 60]);
        $vPast->lines()->create(['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 1, 'rate' => 60, 'discount' => 0, 'next_service_date' => now()->subMonth()->toDateString()]);
        $vPast->transaction()->create(['txn_id' => 'TXN-1', 'amount' => 60, 'method' => 'Cash', 'status' => 'paid']);

        // Null — Repair carries no next-service concept.
        $vNull = $client->visits()->create(['visit_date' => '2026-03-01', 'warranty_months' => 0, 'total_amount' => 80]);
        $vNull->lines()->create(['service_type' => 'Repair', 'repair_desc' => 'Fan motor', 'units' => 1, 'rate' => 80, 'discount' => 0, 'next_service_date' => null]);
        $vNull->transaction()->create(['txn_id' => 'TXN-2', 'amount' => 80, 'method' => 'Cash', 'status' => 'paid']);

        // Further future — must lose to the nearer one below.
        $vFar = $client->visits()->create(['visit_date' => '2026-04-01', 'warranty_months' => 0, 'total_amount' => 60]);
        $vFar->lines()->create(['service_type' => 'Installation', 'unit_type' => 'Cassette', 'units' => 1, 'rate' => 60, 'discount' => 0, 'next_service_date' => now()->addMonths(6)->toDateString()]);
        $vFar->transaction()->create(['txn_id' => 'TXN-3', 'amount' => 60, 'method' => 'Cash', 'status' => 'paid']);

        // Nearest future — this is the one that should win.
        $vNear = $client->visits()->create(['visit_date' => '2026-05-01', 'warranty_months' => 0, 'total_amount' => 60]);
        $vNear->lines()->create(['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 1, 'rate' => 60, 'discount' => 0, 'next_service_date' => now()->addMonth()->toDateString()]);
        $vNear->transaction()->create(['txn_id' => 'TXN-4', 'amount' => 60, 'method' => 'Cash', 'status' => 'paid']);

        $account = $this->service()->accountFor($client->fresh());

        $this->assertSame(now()->addMonth()->toDateString(), $account['next_service_date']);
        $this->assertCount(4, $account['visits']);
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
