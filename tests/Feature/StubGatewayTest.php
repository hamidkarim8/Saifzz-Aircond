<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Receipt;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StubGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.bayarcash.api_secret' => 'secret', 'services.bayarcash.driver' => 'fake']);
    }

    private function pendingTxn(): Transaction
    {
        $client = Client::create(['name' => 'Zainab', 'phone' => '012-3456789', 'address' => 'KL']);
        $visit = $client->visits()->create(['visit_date' => '2026-06-11', 'warranty_months' => 0, 'total_amount' => 110]);
        $visit->lines()->create(['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 2, 'rate' => 55, 'discount' => 0]);

        return $visit->transaction()->create([
            'txn_id' => 'TXN-20260611-001', 'amount' => 110,
            'method' => 'DuitNow QR', 'status' => 'pending', 'gateway_ref' => 'STUB-1',
        ]);
    }

    public function test_stub_hosted_page_renders(): void
    {
        $txn = $this->pendingTxn();

        $this->get(route('dev.bayarcash.show', ['ref' => 'STUB-1', 'order' => $txn->txn_id]))
            ->assertOk()
            ->assertSee('Simulate')
            ->assertSee($txn->txn_id);
    }

    public function test_simulate_paid_drives_full_callback_path(): void
    {
        $txn = $this->pendingTxn();

        $this->post(route('dev.bayarcash.simulate', ['ref' => 'STUB-1']), [
            'order' => $txn->txn_id,
            'outcome' => 'paid',
        ])->assertRedirect(route('payments.return', $txn));

        $this->assertSame('paid', $txn->fresh()->status);
        $this->assertSame(1, Receipt::where('transaction_id', $txn->id)->count());
    }

    public function test_simulate_failed_marks_failed(): void
    {
        $txn = $this->pendingTxn();

        $this->post(route('dev.bayarcash.simulate', ['ref' => 'STUB-1']), [
            'order' => $txn->txn_id,
            'outcome' => 'failed',
        ])->assertRedirect(route('payments.return', $txn));

        $this->assertSame('failed', $txn->fresh()->status);
    }
}
