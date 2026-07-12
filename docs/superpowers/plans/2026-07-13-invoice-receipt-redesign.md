# Invoice / Receipt v2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild the invoice and receipt PDFs as a dense line-item table that shows each service's HP, breaks across pages cleanly, and drops the stray `?` in front of every discount.

**Architecture:** Documents render from a snapshot frozen on the `invoices` / `receipts` row at issue time, via three Blade templates (`documents/layout`, `documents/invoice`, `documents/receipt`) turned into PDFs by dompdf. The per-service card boxes are replaced by one shared line-item table. `SnapshotBuilder` gains `hp_value` so the templates have HP to render at all. Nothing about how documents are minted, numbered, or frozen changes.

**Tech Stack:** Laravel 11, Blade, barryvdh/laravel-dompdf, Pest/PHPUnit feature tests, Postgres.

## Global Constraints

- **No migration.** `service_lines.hp_value` (decimal 3,1, nullable) already exists.
- **No backfill.** Documents already issued keep their frozen snapshot and will not show HP. Every blade read of `hp_value` MUST be null-guarded with `!empty(...)` so a legacy snapshot with no such key renders without error.
- **No U+2212 (`&minus;`), and no non-ASCII character at all, inside any cell carrying the `mono` class or the `table.items td.num` class.** Those cells resolve to DejaVu Sans Mono, which has no glyph for U+2212 and renders it as `?`. This is the root cause of the reported bug. Use an ASCII hyphen `-`.
- Non-mono cells may keep `&mdash;` / `&middot;` — both are present in DejaVu Sans and already render correctly today.
- Run tests with: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter <Name>`. The agent shell has no PHP.
- Blade templates are server-rendered. **No `npm run build` is needed for any change in this plan.**
- Commit after each task. Do not push.

---

### Task 1: Snapshot carries HP

Documents render from `SnapshotBuilder`'s output. It copies every other line field but never copied `hp_value`, so the templates have nothing to show. This task is purely the data layer — no template touches HP yet.

**Files:**
- Modify: `app/Services/Documents/SnapshotBuilder.php:39-48`
- Modify: `app/Http/Controllers/BusinessSettingController.php:104-113` (the sample snapshot behind the Business Settings live preview)
- Test: `tests/Feature/DocumentContentTest.php` (create)

**Interfaces:**
- Consumes: nothing.
- Produces: every element of `$snapshot['lines']` gains an `hp_value` key — a `string|float|null` decimal such as `"1.5"`. Tasks 2 and 3 read it as `$l['hp_value']` and MUST treat a missing key as absent (legacy snapshots have no such key).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/DocumentContentTest.php`:

```php
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
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter DocumentContentTest`
Expected: FAIL — `Failed asserting that an array has the key 'hp_value'`.

- [ ] **Step 3: Add hp_value to the snapshot**

In `app/Services/Documents/SnapshotBuilder.php`, the `lines` map becomes:

```php
            'lines' => $visit->lines->map(fn ($l) => [
                'service_type' => $l->service_type,
                'unit_type' => $l->unit_type,
                'hp_value' => $l->hp_value,
                'units' => $l->units,
                'rate' => $l->rate,
                'discount' => $l->discount,
                'subtotal' => $l->subtotal,
                'repair_desc' => $l->repair_desc,
                'next_service_date' => optional($l->next_service_date)->toDateString(),
            ])->all(),
```

- [ ] **Step 4: Fix the preview's sample snapshot**

`BusinessSettingController::sampleSnapshot()` renders the same Blades in the Business Settings live preview, so its sample must mirror the real snapshot shape or the preview lies about what the customer gets. Its current single line also misuses `unit_type` to hold `'1.5 HP'` — `unit_type` is the mounting type (`Wall Mounted`), HP is its own field. Replace the `lines` and `total_amount` keys:

```php
            'lines' => [
                [
                    'service_type' => 'Aircond Service',
                    'unit_type' => 'Wall Mounted',
                    'hp_value' => 1.0,
                    'units' => 2,
                    'rate' => 60,
                    'discount' => 0,
                    'subtotal' => 120,
                    'repair_desc' => null,
                    'next_service_date' => now()->addMonths(3)->toDateString(),
                ],
                [
                    'service_type' => 'Installation',
                    'unit_type' => 'Wall Mounted',
                    'hp_value' => 2.5,
                    'units' => 1,
                    'rate' => 500,
                    'discount' => 120,
                    'subtotal' => 380,
                    'repair_desc' => null,
                    'next_service_date' => now()->addMonths(3)->toDateString(),
                ],
            ],
            'total_amount' => 500,
```

Two lines, one discounted, differing HP — so the preview exercises the discount column and the HP sub-line rather than hiding them.

- [ ] **Step 5: Run tests to verify they pass**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter "DocumentContentTest|BusinessSettingTest"`
Expected: PASS. `BusinessSettingTest` covers the preview route and must stay green.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Documents/SnapshotBuilder.php app/Http/Controllers/BusinessSettingController.php tests/Feature/DocumentContentTest.php
git commit -m "feat: capture hp_value in the document snapshot"
```

---

### Task 2: Shared layout styles + invoice rebuild

Adds every style both documents need (Task 3 consumes them, adds none of its own), then rebuilds the invoice: two-column header, line-item table, ASCII minus, no Due Date, no Status.

**Files:**
- Modify: `resources/views/documents/layout.blade.php:37-57` (replace the `.line` / `.discount` card styles)
- Modify: `resources/views/documents/invoice.blade.php` (full rewrite)
- Modify: `app/Http/Controllers/DocumentController.php:56-70` (drop `dueDate` + `status`)
- Modify: `app/Http/Controllers/BusinessSettingController.php:68-77` (drop `dueDate` + `status`)
- Test: `tests/Feature/DocumentContentTest.php` (extend)

**Interfaces:**
- Consumes: `$snapshot['lines'][*]['hp_value']` from Task 1.
- Produces: CSS classes Task 3 relies on — `table.split` with `td.bill` / `td.meta`, `.party-label`, `.party-name`, `.party-line`, `table.items` with `td.idx` / `td.desc` / `td.num`, `.svc`, `.svc-meta`, `.disc-amt`, `table.sum` with `td.s-label` / `td.s-value`. Task 3 adds no new CSS.
- Produces: `invoiceData()` no longer returns `dueDate` or `status`. No other caller reads them (verify with grep in Step 5).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/DocumentContentTest.php` (inside the class):

```php
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
        $res->assertDontSee('HP');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter DocumentContentTest`
Expected: FAIL — the HP, ASCII-hyphen, subtotal, and due-date tests all fail against the current card layout.

- [ ] **Step 3: Replace the card styles in the shared layout**

In `resources/views/documents/layout.blade.php`, delete the `/* ── Per-service box ── */` and `/* ── Discount accent ── */` blocks (the `.line`, `.line-title`, `.line table.kv td.k`, `.line table.kv td.v`, `.discount` rules) and put this in their place:

```css
        /* ── Two-column header: bill-to block | doc meta ── */
        table.split { width: 100%; border-collapse: collapse; }
        table.split td.bill { width: 56%; vertical-align: top; padding: 0 12px 0 0; }
        table.split td.meta { width: 44%; vertical-align: top; padding: 0; }
        .party-label { font-size: 9.5px; font-weight: 700; color: #4A6278; text-transform: uppercase; letter-spacing: .8px; margin-bottom: 5px; }
        .party-name { font-weight: 700; color: #0A1628; font-size: 12.5px; }
        .party-line { color: #4A6278; line-height: 1.5; margin-top: 2px; }
        table.split td.meta table.kv td.k { width: 48%; }

        /* ── Line-item table ── */
        table.items { width: 100%; border-collapse: collapse; }
        table.items thead th { font-size: 9px; font-weight: 700; color: #4A6278; text-transform: uppercase; letter-spacing: .6px; text-align: left; padding: 0 0 6px; border-bottom: 1.5px solid #0E2040; }
        table.items thead th.num { text-align: right; padding-left: 6px; }
        table.items tbody tr { page-break-inside: avoid; }
        table.items td { padding: 7px 0; border-bottom: 1px solid #EDF2F7; vertical-align: top; line-height: 1.35; }
        table.items td.idx { width: 6%; color: #4A6278; }
        table.items td.desc { width: 42%; }
        table.items td.num { text-align: right; padding-left: 6px; font-family: 'DejaVu Sans Mono', monospace; font-size: 10.5px; white-space: nowrap; }
        .svc { font-weight: 700; color: #0E2040; }
        .svc-meta { color: #4A6278; font-size: 10px; margin-top: 2px; }
        .disc-amt { color: #16A34A; }

        /* ── Totals summary ── */
        table.sum { width: 100%; border-collapse: collapse; margin-top: 7px; }
        table.sum td { padding: 3px 0; }
        table.sum td.s-label { text-align: right; color: #4A6278; }
        table.sum td.s-value { text-align: right; width: 34%; padding-left: 6px; font-family: 'DejaVu Sans Mono', monospace; font-size: 11.5px; font-weight: 600; }
```

Then add `page-break-inside: avoid;` to the existing `.total` rule so the coloured total block never orphans onto a page of its own:

```css
        .total { border-radius: 8px; padding: 13px 14px; margin-top: 6px; page-break-inside: avoid; }
```

`table.items thead` repeats on every page automatically in dompdf, so a long record keeps its column headers after a break. `page-break-inside: avoid` on `tbody tr` is what stops a service splitting mid-row — the bug Khalid saw.

- [ ] **Step 4: Rewrite the invoice**

Replace `resources/views/documents/invoice.blade.php` entirely:

```blade
@extends('documents.layout', ['accent' => '#6366F1'])

@section('kind', 'INVOICE')

@section('body')
    @php
        $s = $snapshot;
        $money = fn ($v) => 'RM ' . number_format((float) $v, 2);
        // "Manual QR" is the internal label for an in-person DuitNow QR payment.
        $methodLabel = ($s['method'] ?? '') === 'Manual QR' ? 'Duitnow QR Code' : ($s['method'] ?? '');

        $lines = $s['lines'];
        $hasDiscount = collect($lines)->contains(fn ($l) => (float) ($l['discount'] ?? 0) > 0);
        $gross = collect($lines)->sum(fn ($l) => (float) $l['rate'] * (int) $l['units']);
        $totalDiscount = collect($lines)->sum(fn ($l) => (float) ($l['discount'] ?? 0));
    @endphp

    {{-- Bill-to block beside doc meta --}}
    <table class="split">
        <tr>
            <td class="bill">
                <div class="party-label">Bill To</div>
                <div class="party-name">{{ $s['client']['name'] }}</div>
                <div class="party-line">{{ $s['client']['phone'] }}</div>
                <div class="party-line">{{ $s['client']['address'] }}</div>
            </td>
            <td class="meta">
                <table class="kv">
                    <tr>
                        <td class="k">Invoice No.</td>
                        <td class="v mono">{{ $number }}</td>
                    </tr>
                    <tr>
                        <td class="k">Invoice Date</td>
                        <td class="v">{{ $issuedAt->format('d M Y') }}</td>
                    </tr>
                    <tr>
                        <td class="k">Serial No.</td>
                        <td class="v mono">{{ $s['client']['serial_no'] }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <hr>

    {{-- Services --}}
    <div class="sec-label">Services</div>
    <table class="items">
        <thead>
            <tr>
                <th class="idx">#</th>
                <th>Service</th>
                <th class="num">Qty</th>
                <th class="num">Rate</th>
                @if ($hasDiscount)
                    <th class="num">Disc</th>
                @endif
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $i => $l)
                <tr>
                    <td class="idx">{{ $i + 1 }}</td>
                    <td class="desc">
                        <div class="svc">{{ $l['service_type'] }}@if ($l['unit_type']) &middot; {{ $l['unit_type'] }}@endif</div>
                        @if (!empty($l['hp_value']))
                            <div class="svc-meta">{{ number_format((float) $l['hp_value'], 1) }} HP</div>
                        @endif
                    </td>
                    <td class="num">{{ $l['units'] }}</td>
                    <td class="num">{{ $money($l['rate']) }}</td>
                    @if ($hasDiscount)
                        <td class="num disc-amt">@if ((float) ($l['discount'] ?? 0) > 0)- {{ $money($l['discount']) }}@endif</td>
                    @endif
                    <td class="num">{{ $money($l['subtotal']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Subtotal / discount summary, only when something was discounted --}}
    @if ($hasDiscount)
        <table class="sum">
            <tr>
                <td class="s-label">Subtotal</td>
                <td class="s-value">{{ $money($gross) }}</td>
            </tr>
            <tr>
                <td class="s-label">Discount</td>
                <td class="s-value disc-amt">- {{ $money($totalDiscount) }}</td>
            </tr>
        </table>
    @endif

    {{-- Amount due block (indigo) --}}
    <div class="total" style="background: #6366F1;">
        <table>
            <tr>
                <td class="t-label">AMOUNT DUE</td>
                <td class="t-amount">{{ $money($s['total_amount']) }}</td>
            </tr>
        </table>
    </div>

    <div class="foot">Payment via {{ $methodLabel }} &nbsp;&middot;&nbsp; {{ $s['business']['phone'] }}</div>
@endsection
```

Note the discount cell writes a plain ASCII `-`, never `&minus;`. That is the whole of the `?` fix.

- [ ] **Step 5: Drop the now-unused dueDate and status**

Nothing renders them any more. In `app/Http/Controllers/DocumentController.php`, `invoiceData()` becomes:

```php
    /** Invoice view-model — mints the Invoice lazily; renders from its frozen snapshot. */
    private function invoiceData(Transaction $transaction): array
    {
        $invoice = $this->documents->invoiceFor($transaction);

        return [
            'snapshot' => $invoice->snapshot,
            'number' => $invoice->number,
            'issuedAt' => $invoice->created_at,
            'logo' => \App\Support\BrandAssets::logoDataUri(),
        ];
    }
```

In `app/Http/Controllers/BusinessSettingController.php::preview()`, the invoice branch becomes:

```php
        if ($type === 'invoice') {
            return response(view('documents.invoice', [
                'snapshot' => $snapshot,
                'number' => 'INV-'.now()->format('Ymd').'-001',
                'issuedAt' => now(),
                'logo' => $logo,
            ]));
        }
```

Then confirm nothing else reads them:

Run: `docker exec saifzz-aircond-laravel.test-1 grep -rn "dueDate\|invoice_due_days" app/ resources/views/ config/`
Expected: only `config/business.php`'s `invoice_due_days` definition survives. Leave that config key in place — it is harmless and removing it is out of scope. If any *other* reader turns up, stop and report it rather than deleting.

- [ ] **Step 6: Run the tests**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter "DocumentContentTest|DocumentControllerTest|InvoiceGenerationTest|BusinessSettingTest"`
Expected: PASS, all four suites.

- [ ] **Step 7: Commit**

```bash
git add resources/views/documents/layout.blade.php resources/views/documents/invoice.blade.php app/Http/Controllers/DocumentController.php app/Http/Controllers/BusinessSettingController.php tests/Feature/DocumentContentTest.php
git commit -m "feat: rebuild invoice as a line-item table, drop due date and status"
```

---

### Task 3: Receipt rebuild

Same table, plus the two receipt-only items: the heading loses "Official", and next-service collapses from a per-line row into one row under Warranty.

**Files:**
- Modify: `resources/views/documents/receipt.blade.php` (full rewrite)
- Test: `tests/Feature/DocumentContentTest.php` (extend)

**Interfaces:**
- Consumes: the CSS classes produced by Task 2 (`table.split`, `td.bill`, `td.meta`, `.party-label`, `.party-name`, `.party-line`, `table.items`, `td.idx`, `td.desc`, `td.num`, `.svc`, `.svc-meta`, `.disc-amt`, `table.sum`, `td.s-label`, `td.s-value`) and `hp_value` from Task 1. Adds no CSS of its own.
- Produces: nothing consumed downstream.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/DocumentContentTest.php` (inside the class):

```php
    public function test_receipt_heading_drops_the_word_official(): void
    {
        $viewer = $this->viewer();
        $txn = $this->hpTransaction($viewer->id);
        app(\App\Services\Payments\PaymentService::class)->confirmCash($txn);

        $res = $this->actingAs($viewer)->get(route('documents.receipt', $txn->fresh()));

        $res->assertOk();
        $res->assertSee('RECEIPT');
        $res->assertDontSee('OFFICIAL RECEIPT');
    }

    public function test_receipt_shows_next_service_once_when_lines_share_a_date(): void
    {
        $viewer = $this->viewer();
        // Both lines carry next_service_date 2026-10-12.
        $txn = $this->hpTransaction($viewer->id);
        app(\App\Services\Payments\PaymentService::class)->confirmCash($txn);

        $res = $this->actingAs($viewer)->get(route('documents.receipt', $txn->fresh()));

        $res->assertOk();
        $res->assertSee('Next Service');
        $this->assertSame(1, substr_count($res->getContent(), '12 Oct 2026'));
    }

    public function test_receipt_joins_distinct_next_service_dates(): void
    {
        $viewer = $this->viewer();
        $txn = $this->hpTransaction($viewer->id);

        // Push the second line's next service three months later than the first.
        $visit = $txn->visit;
        $visit->lines()->orderBy('id')->get()->last()
            ->update(['next_service_date' => '2027-01-12']);

        app(\App\Services\Payments\PaymentService::class)->confirmCash($txn->fresh());

        $res = $this->actingAs($viewer)->get(route('documents.receipt', $txn->fresh()));

        $res->assertOk();
        $res->assertSee('12 Oct 2026, 12 Jan 2027');
    }

    public function test_receipt_shows_hp_per_line(): void
    {
        $viewer = $this->viewer();
        $txn = $this->hpTransaction($viewer->id);
        app(\App\Services\Payments\PaymentService::class)->confirmCash($txn);

        $res = $this->actingAs($viewer)->get(route('documents.receipt', $txn->fresh()));

        $res->assertSee('1.0 HP');
        $res->assertSee('1.5 HP');
    }
```

`PaymentService::confirmCash()` is what mints the Receipt — a receipt route 404s on an unpaid transaction, so every receipt test must call it first.

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter DocumentContentTest`
Expected: FAIL — the heading still reads `OFFICIAL RECEIPT` and the date appears once per line rather than once per document.

- [ ] **Step 3: Rewrite the receipt**

Replace `resources/views/documents/receipt.blade.php` entirely:

```blade
@extends('documents.layout', ['accent' => '#1E6FAE'])

@section('kind', 'RECEIPT')

@section('body')
    @php
        $s = $snapshot;
        $money = fn ($v) => 'RM ' . number_format((float) $v, 2);
        $date  = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d M Y') : '—';
        // "Manual QR" is the internal label for a DuitNow QR taken in person — show
        // the customer-facing name on the document.
        $methodLabel = ($s['method'] ?? '') === 'Manual QR' ? 'Duitnow QR Code' : ($s['method'] ?? '');

        $lines = $s['lines'];
        $hasDiscount = collect($lines)->contains(fn ($l) => (float) ($l['discount'] ?? 0) > 0);
        $gross = collect($lines)->sum(fn ($l) => (float) $l['rate'] * (int) $l['units']);
        $totalDiscount = collect($lines)->sum(fn ($l) => (float) ($l['discount'] ?? 0));

        // One next-service row for the whole document. Lines normally share a
        // date; when they don't, show each distinct one rather than silently
        // dropping the later.
        $nextServices = collect($lines)
            ->pluck('next_service_date')
            ->filter()
            ->unique()
            ->sort()
            ->map(fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('d M Y'))
            ->implode(', ');
    @endphp

    {{-- Received-from block beside doc meta --}}
    <table class="split">
        <tr>
            <td class="bill">
                <div class="party-label">Received From</div>
                <div class="party-name">{{ $s['client']['name'] }}</div>
                <div class="party-line">{{ $s['client']['phone'] }}</div>
                <div class="party-line">{{ $s['client']['address'] }}</div>
            </td>
            <td class="meta">
                <table class="kv">
                    <tr>
                        <td class="k">Receipt No.</td>
                        <td class="v mono">{{ $number }}</td>
                    </tr>
                    <tr>
                        <td class="k">Date</td>
                        <td class="v">{{ $issuedAt->format('d M Y, h:i A') }}</td>
                    </tr>
                    <tr>
                        <td class="k">Payment</td>
                        <td class="v">{{ $methodLabel }}</td>
                    </tr>
                    <tr>
                        <td class="k">Transaction ID</td>
                        <td class="v mono">{{ $s['txn_id'] }}</td>
                    </tr>
                    <tr>
                        <td class="k">Serial No.</td>
                        <td class="v mono">{{ $s['client']['serial_no'] }}</td>
                    </tr>
                    @if (!empty($s['warranty_months']))
                        <tr>
                            <td class="k">Warranty</td>
                            <td class="v">{{ $s['warranty_months'] }} months &mdash; expires {{ $date($s['warranty_end']) }}</td>
                        </tr>
                    @endif
                    @if ($nextServices !== '')
                        <tr>
                            <td class="k">Next Service</td>
                            <td class="v">{{ $nextServices }}</td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <hr>

    {{-- Services performed --}}
    <div class="sec-label">Services Performed</div>
    <table class="items">
        <thead>
            <tr>
                <th class="idx">#</th>
                <th>Service</th>
                <th class="num">Qty</th>
                <th class="num">Rate</th>
                @if ($hasDiscount)
                    <th class="num">Disc</th>
                @endif
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $i => $l)
                <tr>
                    <td class="idx">{{ $i + 1 }}</td>
                    <td class="desc">
                        <div class="svc">{{ $l['service_type'] }}@if ($l['unit_type']) &middot; {{ $l['unit_type'] }}@endif</div>
                        @if (!empty($l['hp_value']))
                            <div class="svc-meta">{{ number_format((float) $l['hp_value'], 1) }} HP</div>
                        @endif
                        @if (!empty($l['repair_desc']))
                            <div class="svc-meta">{{ $l['repair_desc'] }}</div>
                        @endif
                    </td>
                    <td class="num">{{ $l['units'] }}</td>
                    <td class="num">{{ $money($l['rate']) }}</td>
                    @if ($hasDiscount)
                        <td class="num disc-amt">@if ((float) ($l['discount'] ?? 0) > 0)- {{ $money($l['discount']) }}@endif</td>
                    @endif
                    <td class="num">{{ $money($l['subtotal']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Subtotal / discount summary, only when something was discounted --}}
    @if ($hasDiscount)
        <table class="sum">
            <tr>
                <td class="s-label">Subtotal</td>
                <td class="s-value">{{ $money($gross) }}</td>
            </tr>
            <tr>
                <td class="s-label">Discount</td>
                <td class="s-value disc-amt">- {{ $money($totalDiscount) }}</td>
            </tr>
        </table>
    @endif

    {{-- Total paid block (navy) --}}
    <div class="total" style="background: #0E2040;">
        <table>
            <tr>
                <td class="t-label">TOTAL PAID</td>
                <td class="t-amount">{{ $money($s['total_amount']) }}</td>
            </tr>
        </table>
    </div>

    <div class="foot">Thank you for trusting {{ $s['business']['name'] }}.</div>
@endsection
```

- [ ] **Step 4: Run the full suite**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test`
Expected: PASS, every test. The suite stood at 355 before this work; it should now be 355 + the tests added in Tasks 1-3.

- [ ] **Step 5: Commit**

```bash
git add resources/views/documents/receipt.blade.php tests/Feature/DocumentContentTest.php
git commit -m "feat: rebuild receipt as a line-item table, drop 'Official', consolidate next service"
```

---

### Task 4: Verify against a real PDF

The `?` bug is invisible to every test above — they assert on HTML, and the character only breaks once dompdf resolves the font. This task is the one that actually proves the reported bugs are dead. Do not skip it, and do not substitute a passing test suite for it.

**Files:**
- Create: `scratchpad/verify-doc.php` (throwaway, not committed)

- [ ] **Step 1: Render a real PDF from a four-line HP record**

Write a tinker script that builds a visit with four discounted HP-based Installation lines — the shape from Khalid's reported PDF — mints the invoice, and writes the PDF to disk:

```php
$client = \App\Models\Client::create([
    'name' => 'Sheikh Esmail',
    'phone' => '0193294915',
    'address' => 'B-05-01, CASA GREEN CONDOMINIUM, JALAN CASA GREEN, 43200 CHERAS SELANGOR',
]);
$visit = $client->visits()->create([
    'visit_date' => now()->toDateString(),
    'warranty_months' => 3,
    'total_amount' => 1300,
]);
foreach ([[1.0, 370, 100], [1.5, 400, 100], [2.0, 470, 120], [2.5, 500, 120]] as [$hp, $rate, $disc]) {
    $visit->lines()->create([
        'service_type' => 'Installation', 'unit_type' => 'Wall Mounted',
        'units' => 1, 'rate' => $rate, 'discount' => $disc, 'hp_value' => $hp,
        'next_service_date' => now()->addMonths(6)->toDateString(),
    ]);
}
$txn = $visit->transaction()->create([
    'txn_id' => 'TXN-VERIFY-001', 'amount' => 1300,
    'method' => 'Manual QR', 'status' => 'pending',
]);

$invoice = app(\App\Services\Documents\DocumentService::class)->invoiceFor($txn);
$pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('documents.invoice', [
    'snapshot' => $invoice->snapshot,
    'number' => $invoice->number,
    'issuedAt' => $invoice->created_at,
    'logo' => \App\Support\BrandAssets::logoDataUri(),
]);
file_put_contents('/var/www/html/public/verify-invoice.pdf', $pdf->output());
echo "written\n";
```

Run it: `docker exec -i saifzz-aircond-laravel.test-1 php artisan tinker < scratchpad/verify-doc.php`

If `DocumentService`'s class name or `invoiceFor` signature differs from the above, read `app/Http/Controllers/DocumentController.php` and match what it actually calls — do not guess.

- [ ] **Step 2: Read the PDF back and confirm each fix**

Copy it out and open it: `docker cp saifzz-aircond-laravel.test-1:/var/www/html/public/verify-invoice.pdf ./scratchpad/verify-invoice.pdf`, then read `scratchpad/verify-invoice.pdf` with the Read tool.

Confirm, explicitly, one by one:
- No `?` anywhere near a discount amount. Every discount reads `- RM 100.00`.
- Each of the four lines shows its own HP (`1.0 HP` … `2.5 HP`).
- No Due Date row, no Status pill.
- All four services and the Amount Due block fit on **one** page.
- Subtotal `RM 1,740.00` and Discount `- RM 440.00` appear above Amount Due `RM 1,300.00`.

- [ ] **Step 3: Confirm the page break on a long record**

Re-run Step 1 with the four-line `foreach` extended to ~16 lines (repeat the HP array four times) and render again. Read the PDF back and confirm: it runs to two pages, the column headers repeat on page two, and **no service row is cut in half across the boundary**.

- [ ] **Step 4: Clean up**

```bash
docker exec saifzz-aircond-laravel.test-1 rm -f /var/www/html/public/verify-invoice.pdf
rm -f scratchpad/verify-doc.php scratchpad/verify-invoice.pdf
```

The verification visit/transaction rows are local-only test data; leave or delete them at your discretion, but do not let `public/verify-invoice.pdf` reach a commit.

- [ ] **Step 5: Flip the feedback items to TESTING**

In `docs/FEEDBACK-13072026.md`, change every `OPEN` to `TESTING` (BUG-009, BUG-010, CHG-028, CHG-029, CHG-030, CHG-031, FEAT-021).

```bash
git add docs/FEEDBACK-13072026.md
git commit -m "docs: invoice/receipt v2 items to TESTING"
```

---

## Deployment Notes

- **No migration.** Nothing to run on production.
- **No `npm run build`.** Blade templates are server-rendered; the Vite bundle is untouched.
- Documents issued before this ships keep their frozen snapshot and will not show HP. This is intended — see the spec's decision table.
