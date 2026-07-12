<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Documents\SnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentContentTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
            'permissions' => ['view_clients'],
        ]);
    }

    /**
     * A visit with two HP-based Installation lines at different HP ratings and
     * different discounts — the exact shape Khalid reported as unreadable.
     */
    private function hpTransaction(?int $technicianId = null): Transaction
    {
        $client = Client::create([
            'name' => 'Zainab',
            'phone' => '012-3456789',
            'address' => 'No. 5, Jalan Maju, KL',
        ]);

        $visit = $client->visits()->create([
            'visit_date' => '2026-07-12',
            'warranty_months' => 3,
            'total_amount' => 570,
            'technician_id' => $technicianId,
        ]);

        $visit->lines()->create([
            'service_type' => 'Installation', 'unit_type' => 'Wall Mounted',
            'units' => 1, 'rate' => 370, 'discount' => 100, 'hp_value' => 1.0,
            'next_service_date' => '2026-10-12',
        ]);
        $visit->lines()->create([
            'service_type' => 'Installation', 'unit_type' => 'Wall Mounted',
            'units' => 1, 'rate' => 400, 'discount' => 100, 'hp_value' => 1.5,
            'next_service_date' => '2026-10-12',
        ]);

        return $visit->transaction()->create([
            'txn_id' => 'TXN-20260712-001', 'amount' => 570,
            'method' => 'DuitNow QR', 'status' => 'pending',
        ]);
    }

    public function test_snapshot_captures_hp_value_per_line(): void
    {
        $txn = $this->hpTransaction();

        $snapshot = app(SnapshotBuilder::class)->forTransaction($txn);

        $this->assertCount(2, $snapshot['lines']);
        $this->assertEquals(1.0, (float) $snapshot['lines'][0]['hp_value']);
        $this->assertEquals(1.5, (float) $snapshot['lines'][1]['hp_value']);
    }

    public function test_snapshot_hp_value_is_null_for_non_hp_line(): void
    {
        $client = Client::create(['name' => 'Ali', 'phone' => '011-1', 'address' => 'KL']);
        $visit = $client->visits()->create([
            'visit_date' => '2026-07-12', 'warranty_months' => 0, 'total_amount' => 60,
        ]);
        $visit->lines()->create([
            'service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted',
            'units' => 1, 'rate' => 60, 'discount' => 0,
        ]);
        $txn = $visit->transaction()->create([
            'txn_id' => 'TXN-20260712-002', 'amount' => 60,
            'method' => 'Cash', 'status' => 'pending',
        ]);

        $snapshot = app(SnapshotBuilder::class)->forTransaction($txn);

        $this->assertArrayHasKey('hp_value', $snapshot['lines'][0]);
        $this->assertNull($snapshot['lines'][0]['hp_value']);
    }

    public function test_invoice_shows_hp_per_line(): void
    {
        $viewer = $this->viewer();
        $txn = $this->hpTransaction($viewer->id);

        $res = $this->actingAs($viewer)->get(route('documents.invoice', $txn));

        $res->assertOk();
        $res->assertSee('1.0 HP');
        $res->assertSee('1.5 HP');
    }

    public function test_invoice_discount_uses_ascii_hyphen_not_minus_sign(): void
    {
        $viewer = $this->viewer();
        $txn = $this->hpTransaction($viewer->id);

        $res = $this->actingAs($viewer)->get(route('documents.invoice', $txn));

        // U+2212 in a mono cell is what dompdf renders as "?" — it must not appear.
        $this->assertStringNotContainsString("\u{2212}", $res->getContent());
        $this->assertStringNotContainsString('&minus;', $res->getContent());
        $res->assertSee('- RM 100.00');
    }

    public function test_invoice_omits_due_date_and_status(): void
    {
        $viewer = $this->viewer();
        $txn = $this->hpTransaction($viewer->id);

        $res = $this->actingAs($viewer)->get(route('documents.invoice', $txn));

        $res->assertDontSee('Due Date');
        $res->assertDontSee('Pending');
    }

    public function test_invoice_shows_subtotal_and_total_discount(): void
    {
        $viewer = $this->viewer();
        $txn = $this->hpTransaction($viewer->id);

        $res = $this->actingAs($viewer)->get(route('documents.invoice', $txn));

        $res->assertSee('Subtotal');
        $res->assertSee('RM 770.00');   // 370 + 400 gross
        $res->assertSee('- RM 200.00'); // 100 + 100 discount
    }

    public function test_invoice_renders_legacy_snapshot_without_hp_key(): void
    {
        $viewer = $this->viewer();
        $txn = $this->hpTransaction($viewer->id);

        // Mint an invoice by hand whose frozen snapshot predates hp_value —
        // exactly what every already-issued document on production looks like.
        $legacy = app(\App\Services\Documents\SnapshotBuilder::class)->forTransaction($txn);
        foreach ($legacy['lines'] as $i => $line) {
            unset($legacy['lines'][$i]['hp_value']);
        }

        \App\Models\Invoice::create([
            'number' => 'INV-LEGACY-001',
            'transaction_id' => $txn->id,
            'amount' => $txn->amount,
            'snapshot' => $legacy,
        ]);

        $res = $this->actingAs($viewer)->get(route('documents.invoice', $txn));

        $res->assertOk();
        $res->assertSee('INV-LEGACY-001');
        // Not assertDontSee('HP'): the base64-encoded brand logo always present
        // in the doc <head> coincidentally contains the substring "HP" dozens
        // of times, and the shared layout's ".svc-meta { ... }" CSS rule is
        // present on every document regardless of data. Assert on the actual
        // rendered per-line HP badge markup instead — it only renders when
        // hp_value is present in the line.
        $res->assertDontSee('<div class="svc-meta">', false);
    }
}
