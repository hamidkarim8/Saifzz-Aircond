<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentControllerTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
            'permissions' => ['view_clients'],
        ]);
    }

    private function transaction(string $status = 'pending', ?int $technicianId = null): Transaction
    {
        $client = Client::create(['name' => 'Zainab', 'phone' => '012-3456789', 'address' => 'No. 5, Jalan Maju, KL']);
        $visit = $client->visits()->create([
            'visit_date' => '2026-06-08', 'warranty_months' => 3, 'total_amount' => 110,
            'technician_id' => $technicianId,
        ]);
        $visit->lines()->create([
            'service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted',
            'units' => 2, 'rate' => 60, 'discount' => 10, 'next_service_date' => '2026-09-05',
        ]);

        return $visit->transaction()->create([
            'txn_id' => 'TXN-20260608-001', 'amount' => 110, 'method' => 'DuitNow QR', 'status' => $status,
        ]);
    }

    public function test_invoice_view_renders_html_with_number(): void
    {
        $viewer = $this->viewer();
        $txn = $this->transaction('pending', $viewer->id);

        $res = $this->actingAs($viewer)->get(route('documents.invoice', $txn));

        $res->assertOk();
        $res->assertSee('INV-', false);
        $res->assertSee('Zainab');
    }

    public function test_invoice_pdf_downloads(): void
    {
        $viewer = $this->viewer();
        $txn = $this->transaction('pending', $viewer->id);

        $res = $this->actingAs($viewer)->get(route('documents.invoice.pdf', $txn));

        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $res->getContent());
        $this->assertStringContainsString('attachment', $res->headers->get('content-disposition'));
    }

    public function test_receipt_view_renders_for_paid_transaction(): void
    {
        $viewer = $this->viewer();
        $txn = $this->transaction('pending', $viewer->id);
        app(PaymentService::class)->confirmCash($txn); // issues a Receipt

        $res = $this->actingAs($viewer)->get(route('documents.receipt', $txn->fresh()));

        $res->assertOk();
        $res->assertSee('RCP-', false);
    }

    public function test_receipt_returns_404_when_unpaid(): void
    {
        $viewer = $this->viewer();
        $txn = $this->transaction('pending', $viewer->id);

        $this->actingAs($viewer)
            ->get(route('documents.receipt', $txn))
            ->assertNotFound();
    }

    public function test_receipt_pdf_downloads_for_paid_transaction(): void
    {
        $viewer = $this->viewer();
        $txn = $this->transaction('pending', $viewer->id);
        app(PaymentService::class)->confirmCash($txn);

        $res = $this->actingAs($viewer)->get(route('documents.receipt.pdf', $txn->fresh()));

        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }

    public function test_requires_view_clients_permission(): void
    {
        $txn = $this->transaction();
        $tech = User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
            'permissions' => ['record_service'],
        ]);

        $this->actingAs($tech)->get(route('documents.invoice', $txn))->assertForbidden();
    }

    public function test_guest_redirected_to_login(): void
    {
        $txn = $this->transaction();

        $this->get(route('documents.invoice', $txn))->assertRedirect(route('login'));
    }
}
