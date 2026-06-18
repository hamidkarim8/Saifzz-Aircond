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

    private function pendingTransaction(float $amount = 110.0, ?int $technicianId = null): Transaction
    {
        $client = Client::create(['name' => 'Zainab', 'phone' => '012-3456789', 'address' => 'KL']);
        $visit = $client->visits()->create([
            'visit_date' => '2026-06-11',
            'warranty_months' => 0,
            'total_amount' => $amount,
            'technician_id' => $technicianId,
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

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_admin_confirms_manual_qr_marks_paid_and_issues_receipt(): void
    {
        $admin = $this->admin();
        $txn = $this->pendingTransaction(110.0, $admin->id);

        $this->actingAs($admin)
            ->post(route('payments.manualQr', $txn))
            ->assertRedirect(route('payments.return', $txn));

        $txn->refresh();
        $this->assertSame('paid', $txn->status);
        $this->assertSame('Manual QR', $txn->method);
        $this->assertNotNull($txn->paid_at);

        $receipt = Receipt::where('transaction_id', $txn->id)->first();
        $this->assertNotNull($receipt);
        $this->assertMatchesRegularExpression('/^RCP-\d{8}-001$/', $receipt->number);
    }

    public function test_manual_qr_confirm_is_idempotent(): void
    {
        $admin = $this->admin();
        $txn = $this->pendingTransaction(110.0, $admin->id);

        $this->actingAs($admin)->post(route('payments.manualQr', $txn));
        $this->actingAs($admin)->post(route('payments.manualQr', $txn));

        $this->assertSame(1, Receipt::where('transaction_id', $txn->id)->count());
    }

    public function test_non_admin_collector_cannot_use_manual_qr(): void
    {
        $collector = $this->collector();
        $txn = $this->pendingTransaction(110.0, $collector->id);

        $this->actingAs($collector)
            ->post(route('payments.manualQr', $txn))
            ->assertForbidden();
    }

    public function test_user_without_collect_payment_cannot_use_manual_qr(): void
    {
        $txn = $this->pendingTransaction();
        $tech = User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
            'permissions' => ['record_service'],
        ]);

        $this->actingAs($tech)
            ->post(route('payments.manualQr', $txn))
            ->assertForbidden();
    }

    public function test_admin_cannot_manual_qr_a_cross_tenant_transaction(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $admin->update(['tenant_id' => $admin->id]); // self-root tenant A

        // Tenant B: a distinct self-root admin (clients.tenant_id is an FK to users.id).
        $otherAdmin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $otherAdmin->update(['tenant_id' => $otherAdmin->id]);

        $client = Client::create(['name' => 'OtherTenant', 'phone' => '012-0000000', 'address' => 'KL', 'tenant_id' => $otherAdmin->id]);
        $visit = $client->visits()->create([
            'visit_date' => '2026-06-11', 'warranty_months' => 0,
            'total_amount' => 50.0, 'tenant_id' => $otherAdmin->id,
        ]);
        $txn = $visit->transaction()->create([
            'txn_id' => 'TXN-20260611-777', 'amount' => 50.0, 'method' => 'Cash', 'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->post(route('payments.manualQr', $txn))
            ->assertForbidden();
    }

    public function test_cash_confirm_marks_paid_and_creates_receipt(): void
    {
        $collector = $this->collector();
        $txn = $this->pendingTransaction(110.0, $collector->id);

        $this->actingAs($collector)
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
        $collector = $this->collector();
        $txn = $this->pendingTransaction(110.0, $collector->id);

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
        $collector = $this->collector();
        $txn = $this->pendingTransaction(110.0, $collector->id);

        $this->actingAs($collector)
            ->get(route('payments.show', $txn))
            ->assertOk();
    }

    public function test_paid_transaction_redirects_show_to_return(): void
    {
        $collector = $this->collector();
        $txn = $this->pendingTransaction(110.0, $collector->id);
        $this->actingAs($collector)->post(route('payments.cash', $txn));

        $this->actingAs($collector)
            ->get(route('payments.show', $txn))
            ->assertRedirect(route('payments.return', $txn));
    }

    public function test_return_page_renders(): void
    {
        $collector = $this->collector();
        $txn = $this->pendingTransaction(110.0, $collector->id);

        $this->actingAs($collector)
            ->get(route('payments.return', $txn))
            ->assertOk();
    }
}
