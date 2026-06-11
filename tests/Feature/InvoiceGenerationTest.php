<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Services\Documents\DocumentService;
use App\Services\Documents\SnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceGenerationTest extends TestCase
{
    use RefreshDatabase;

    private function transaction(): Transaction
    {
        $client = Client::create(['name' => 'Zainab', 'phone' => '012-3456789', 'address' => 'KL']);
        $visit = $client->visits()->create([
            'visit_date' => '2026-06-08', 'warranty_months' => 0, 'total_amount' => 110,
        ]);
        $visit->lines()->create([
            'service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted',
            'units' => 2, 'rate' => 55, 'discount' => 0,
        ]);

        return $visit->transaction()->create([
            'txn_id' => 'TXN-20260608-001', 'amount' => 110, 'method' => 'Cash', 'status' => 'pending',
        ]);
    }

    private function service(): DocumentService
    {
        return new DocumentService(new SnapshotBuilder);
    }

    public function test_invoice_is_created_lazily_and_is_idempotent(): void
    {
        $txn = $this->transaction();

        $first = $this->service()->invoiceFor($txn);
        $second = $this->service()->invoiceFor($txn);

        $this->assertMatchesRegularExpression('/^INV-\d{8}-001$/', $first->number);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Invoice::where('transaction_id', $txn->id)->count());
        $this->assertSame('Zainab', $first->snapshot['client']['name']);
        $this->assertSame('110.00', (string) $first->amount);
    }
}
