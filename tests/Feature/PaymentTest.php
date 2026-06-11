<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Receipt;
use App\Models\ServiceVisit;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private function collector(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
            'permissions' => ['view_clients', 'record_service', 'collect_payment'],
        ]);
    }

    private function pendingTransaction(float $amount = 110.0): Transaction
    {
        $client = Client::create(['name' => 'Zainab', 'phone' => '012-3456789', 'address' => 'KL']);
        $visit = $client->visits()->create([
            'visit_date' => '2026-06-11',
            'warranty_months' => 0,
            'total_amount' => $amount,
        ]);
        $visit->lines()->create([
            'service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted',
            'units' => 2, 'rate' => 55, 'discount' => 0,
        ]);

        return $visit->transaction()->create([
            'txn_id' => 'TXN-20260611-001',
            'amount' => $amount,
            'method' => 'Cash',
            'status' => 'pending',
        ]);
    }

    public function test_cash_confirm_marks_paid_and_creates_receipt(): void
    {
        $txn = $this->pendingTransaction();

        $this->actingAs($this->collector())
            ->post(route('payments.cash', $txn))
            ->assertRedirect(route('payments.return', $txn));

        $txn->refresh();
        $this->assertSame('paid', $txn->status);
        $this->assertSame('Cash', $txn->method);
        $this->assertNotNull($txn->paid_at);

        $receipt = Receipt::where('transaction_id', $txn->id)->first();
        $this->assertNotNull($receipt);
        $this->assertMatchesRegularExpression('/^RCP-\d{8}-001$/', $receipt->number);
        $this->assertSame('110.00', $receipt->amount);
        $this->assertSame('Zainab', $receipt->snapshot['client']['name']);
    }

    public function test_cash_confirm_is_idempotent(): void
    {
        $txn = $this->pendingTransaction();
        $collector = $this->collector();

        $this->actingAs($collector)->post(route('payments.cash', $txn));
        $this->actingAs($collector)->post(route('payments.cash', $txn));

        $this->assertSame(1, Receipt::where('transaction_id', $txn->id)->count());
    }

    public function test_collect_payment_permission_required_for_cash(): void
    {
        $txn = $this->pendingTransaction();
        $tech = User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
            'permissions' => ['record_service'],
        ]);

        $this->actingAs($tech)->post(route('payments.cash', $txn))->assertForbidden();
    }

    public function test_payment_page_renders_for_collector(): void
    {
        $txn = $this->pendingTransaction();

        $this->actingAs($this->collector())
            ->get(route('payments.show', $txn))
            ->assertOk();
    }

    public function test_paid_transaction_redirects_show_to_return(): void
    {
        $txn = $this->pendingTransaction();
        $this->actingAs($this->collector())->post(route('payments.cash', $txn));

        $this->actingAs($this->collector())
            ->get(route('payments.show', $txn))
            ->assertRedirect(route('payments.return', $txn));
    }

    public function test_return_page_renders(): void
    {
        $txn = $this->pendingTransaction();

        $this->actingAs($this->collector())
            ->get(route('payments.return', $txn))
            ->assertOk();
    }
}
