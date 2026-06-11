<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Receipt;
use App\Models\Transaction;
use App\Services\Payments\Support\Checksum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.bayarcash.api_secret' => 'secret']);
    }

    private function pendingTxn(float $amount = 110.0): Transaction
    {
        $client = Client::create(['name' => 'Zainab', 'phone' => '012-3456789', 'address' => 'KL']);
        $visit = $client->visits()->create(['visit_date' => '2026-06-11', 'warranty_months' => 0, 'total_amount' => $amount]);
        $visit->lines()->create(['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 2, 'rate' => 55, 'discount' => 0]);

        return $visit->transaction()->create([
            'txn_id' => 'TXN-20260611-001', 'amount' => $amount,
            'method' => 'DuitNow QR', 'status' => 'pending', 'gateway_ref' => 'STUB-1',
        ]);
    }

    private function payload(string $order, string $amount, int $status, string $ref = 'STUB-1', ?string $secret = 'secret'): array
    {
        $fields = [$ref, $order, $amount, (string) $status];

        return [
            'transaction_id' => $ref,
            'order_number' => $order,
            'amount' => $amount,
            'status' => $status,
            'checksum' => Checksum::make($fields, $secret ?? 'secret'),
        ];
    }

    public function test_valid_success_callback_marks_paid_and_creates_receipt(): void
    {
        $txn = $this->pendingTxn();

        $this->post(route('webhooks.bayarcash'), $this->payload($txn->txn_id, '110.00', 3))
            ->assertOk();

        $txn->refresh();
        $this->assertSame('paid', $txn->status);
        $this->assertNotNull($txn->paid_at);
        $this->assertSame(1, Receipt::where('transaction_id', $txn->id)->count());
    }

    public function test_invalid_checksum_is_rejected_with_no_state_change(): void
    {
        $txn = $this->pendingTxn();
        $bad = $this->payload($txn->txn_id, '110.00', 3);
        $bad['checksum'] = 'tampered';

        $this->post(route('webhooks.bayarcash'), $bad)->assertForbidden();

        $this->assertSame('pending', $txn->fresh()->status);
        $this->assertSame(0, Receipt::count());
    }

    public function test_duplicate_delivery_is_idempotent(): void
    {
        $txn = $this->pendingTxn();
        $payload = $this->payload($txn->txn_id, '110.00', 3);

        $this->post(route('webhooks.bayarcash'), $payload)->assertOk();
        $this->post(route('webhooks.bayarcash'), $payload)->assertOk();

        $this->assertSame(1, Receipt::where('transaction_id', $txn->id)->count());
    }

    public function test_failed_callback_marks_failed_without_receipt(): void
    {
        $txn = $this->pendingTxn();

        $this->post(route('webhooks.bayarcash'), $this->payload($txn->txn_id, '110.00', 4))
            ->assertOk();

        $this->assertSame('failed', $txn->fresh()->status);
        $this->assertSame(0, Receipt::count());
    }

    public function test_amount_mismatch_is_rejected(): void
    {
        $txn = $this->pendingTxn(110.0);

        $this->post(route('webhooks.bayarcash'), $this->payload($txn->txn_id, '5.00', 3))
            ->assertOk(); // accepted (200) but ignored

        $this->assertSame('pending', $txn->fresh()->status);
        $this->assertSame(0, Receipt::count());
    }

    public function test_unknown_order_is_ignored(): void
    {
        $this->post(route('webhooks.bayarcash'), $this->payload('TXN-NOPE', '110.00', 3))
            ->assertOk();

        $this->assertSame(0, Receipt::count());
    }
}
