<?php

namespace Tests\Feature\Portal;

use App\Models\Client;
use App\Models\Transaction;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalReceiptTest extends TestCase
{
    use RefreshDatabase;

    /** Build a client with one txn; pay it when $paid so a Receipt is issued. */
    private function clientWithTxn(bool $paid = true): array
    {
        $client = Client::create(['name' => 'Zainab', 'phone' => '012-345 6789', 'address' => 'No. 5, KL']);
        $visit = $client->visits()->create(['visit_date' => '2026-06-08', 'warranty_months' => 3, 'total_amount' => 110]);
        $visit->lines()->create(['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 2, 'rate' => 60, 'discount' => 10, 'next_service_date' => '2026-09-05']);
        $txn = $visit->transaction()->create(['txn_id' => 'TXN-20260608-001', 'amount' => 110, 'method' => 'Cash', 'status' => 'pending']);

        if ($paid) {
            app(PaymentService::class)->confirmCash($txn);
            $txn = $txn->fresh();
        }

        return [$client, $txn];
    }

    public function test_own_paid_receipt_renders(): void
    {
        [$client, $txn] = $this->clientWithTxn();

        $res = $this->withSession(['portal_client_id' => $client->id])->get(route('portal.receipt', $txn));

        $res->assertOk();
        $res->assertSee('RCP-', false);
    }

    public function test_own_paid_receipt_pdf_downloads(): void
    {
        [$client, $txn] = $this->clientWithTxn();

        $res = $this->withSession(['portal_client_id' => $client->id])->get(route('portal.receipt.pdf', $txn));

        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }

    public function test_unpaid_transaction_is_404(): void
    {
        [$client, $txn] = $this->clientWithTxn(paid: false);

        $this->withSession(['portal_client_id' => $client->id])
            ->get(route('portal.receipt', $txn))
            ->assertNotFound();
    }

    public function test_other_clients_receipt_is_404(): void
    {
        [, $txn] = $this->clientWithTxn();
        $other = Client::create(['name' => 'Other', 'phone' => '019-111 2222', 'address' => 'Elsewhere']);

        $this->withSession(['portal_client_id' => $other->id])
            ->get(route('portal.receipt', $txn))
            ->assertNotFound();
    }

    public function test_unauthenticated_is_redirected_to_login(): void
    {
        [, $txn] = $this->clientWithTxn();

        $this->get(route('portal.receipt', $txn))->assertRedirect(route('portal.login'));
    }
}
