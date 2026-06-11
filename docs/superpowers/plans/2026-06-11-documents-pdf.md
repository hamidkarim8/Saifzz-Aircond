# Module 6 — Documents (Invoice & Receipt PDF) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans or
> superpowers:subagent-driven-development to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Render an Invoice (`INV-…`, unpaid bill) and Receipt (`RCP-…`, proof of payment)
as both an on-screen HTML view and a downloadable PDF, from frozen snapshots.

**Architecture:** One Blade template per doc type is the single source of truth — the *view*
route returns it as HTML, the *download* route runs the same Blade through dompdf. Invoices
are minted lazily (`firstOrCreate`) on first view/download; Receipts already exist (Payments).
A shared `SnapshotBuilder` freezes client+line+payment+issuer data for both doc types.

**Tech Stack:** Laravel 13, `barryvdh/laravel-dompdf`, Blade, Inertia/Vue 3, PostgreSQL,
PHPUnit. Run everything through Sail: `docker compose exec laravel.test <cmd>`.

**Spec:** `docs/superpowers/specs/2026-06-11-documents-pdf-design.md`

---

## File Structure

**Create**
- `config/business.php` — issuer identity (name/address/phone) + invoice due-days.
- `app/Services/Documents/SnapshotBuilder.php` — freezes a transaction into a doc snapshot.
- `app/Services/Documents/DocumentService.php` — lazy invoice minting + INV numbering.
- `app/Http/Controllers/DocumentController.php` — 4 actions (invoice/receipt × view/pdf).
- `resources/views/documents/layout.blade.php` — shared dompdf-safe shell.
- `resources/views/documents/invoice.blade.php`
- `resources/views/documents/receipt.blade.php`
- `tests/Feature/SnapshotBuilderTest.php`
- `tests/Feature/InvoiceGenerationTest.php`
- `tests/Feature/DocumentControllerTest.php`

**Modify**
- `app/Services/Payments/PaymentService.php` — delegate snapshot to `SnapshotBuilder`.
- `routes/web.php` — 4 document routes gated `can:view_clients`.
- `.env`, `.env.example` — `BUSINESS_*`.
- `resources/js/Pages/ServiceRecords/Show.vue` — invoice/receipt links.
- `resources/js/Pages/Payments/Return.vue` — receipt view/download links.
- `resources/js/Pages/Clients/Show.vue` — per-visit doc link.

---

## Task 1: Install dompdf + business config

**Files:**
- Create: `config/business.php`
- Modify: `.env`, `.env.example`

- [ ] **Step 1: Install the PDF package**

Run: `docker compose exec laravel.test composer require barryvdh/laravel-dompdf`
Expected: package installed, auto-discovered (`Barryvdh\DomPDF\ServiceProvider`).

- [ ] **Step 2: Create `config/business.php`**

```php
<?php

return [
    'name' => env('BUSINESS_NAME', 'Saifzz Aircond Services'),
    'address' => env('BUSINESS_ADDRESS', 'No. 12, Jalan Teknologi, KL'),
    'phone' => env('BUSINESS_PHONE', '012-9876543'),
    // Days from invoice issue until payment is due (mockup shows a 7-day window).
    'invoice_due_days' => (int) env('BUSINESS_INVOICE_DUE_DAYS', 7),
];
```

- [ ] **Step 3: Append `BUSINESS_*` to `.env` and `.env.example`**

```dotenv
BUSINESS_NAME="Saifzz Aircond Services"
BUSINESS_ADDRESS="No. 12, Jalan Teknologi, KL"
BUSINESS_PHONE="012-9876543"
```

- [ ] **Step 4: Commit**

```bash
git add composer.json composer.lock config/business.php .env.example
git commit -m "feat(documents): add dompdf + business identity config"
```

---

## Task 2: SnapshotBuilder — extract + complete

Moves the snapshot out of `PaymentService` and adds the keys the mockup needs
(`warranty_months`, per-line `next_service_date`, `business`).

**Files:**
- Create: `app/Services/Documents/SnapshotBuilder.php`
- Test: `tests/Feature/SnapshotBuilderTest.php`
- Modify: `app/Services/Payments/PaymentService.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/SnapshotBuilderTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Transaction;
use App\Services\Documents\SnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SnapshotBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function transaction(): Transaction
    {
        $client = Client::create([
            'name' => 'Zainab', 'phone' => '012-3456789', 'address' => 'No. 5, Jalan Maju, KL',
        ]);
        $visit = $client->visits()->create([
            'visit_date' => '2026-06-08', 'warranty_months' => 3, 'total_amount' => 110,
        ]);
        $visit->lines()->create([
            'service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted',
            'units' => 2, 'rate' => 60, 'discount' => 10, 'next_service_date' => '2026-09-05',
        ]);

        return $visit->transaction()->create([
            'txn_id' => 'TXN-20260608-001', 'amount' => 110,
            'method' => 'DuitNow QR', 'status' => 'pending',
        ]);
    }

    public function test_snapshot_includes_warranty_next_service_and_business(): void
    {
        $snapshot = (new SnapshotBuilder)->forTransaction($this->transaction());

        $this->assertSame(3, $snapshot['warranty_months']);
        $this->assertSame('2026-09-05', $snapshot['lines'][0]['next_service_date']);
        $this->assertSame(config('business.name'), $snapshot['business']['name']);
        $this->assertSame('Zainab', $snapshot['client']['name']);
        $this->assertSame('110.00', (string) $snapshot['total_amount']);
    }
}
```

- [ ] **Step 2: Run it — verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=SnapshotBuilderTest`
Expected: FAIL — `Class "App\Services\Documents\SnapshotBuilder" not found`.

- [ ] **Step 3: Create `app/Services/Documents/SnapshotBuilder.php`**

```php
<?php

namespace App\Services\Documents;

use App\Models\Transaction;

final class SnapshotBuilder
{
    /**
     * Freeze client + line + payment + issuer details so an issued document
     * (invoice / receipt) reprints identically regardless of later edits.
     */
    public function forTransaction(Transaction $transaction): array
    {
        $visit = $transaction->visit()->with(['client', 'lines'])->first();

        return [
            'business' => [
                'name' => config('business.name'),
                'address' => config('business.address'),
                'phone' => config('business.phone'),
            ],
            'txn_id' => $transaction->txn_id,
            'method' => $transaction->method,
            'paid_at' => optional($transaction->paid_at)->toIso8601String(),
            'client' => [
                'name' => $visit->client->name,
                'serial_no' => $visit->client->serial_no,
                'phone' => $visit->client->phone,
                'address' => $visit->client->address,
            ],
            'visit_date' => optional($visit->visit_date)->toDateString(),
            'warranty_months' => $visit->warranty_months,
            'warranty_end' => optional($visit->warranty_end)->toDateString(),
            'lines' => $visit->lines->map(fn ($l) => [
                'service_type' => $l->service_type,
                'unit_type' => $l->unit_type,
                'gas_option' => $l->gas_option,
                'units' => $l->units,
                'rate' => $l->rate,
                'discount' => $l->discount,
                'subtotal' => $l->subtotal,
                'repair_desc' => $l->repair_desc,
                'next_service_date' => optional($l->next_service_date)->toDateString(),
            ])->all(),
            'total_amount' => $visit->total_amount,
        ];
    }
}
```

- [ ] **Step 4: Run it — verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=SnapshotBuilderTest`
Expected: PASS.

- [ ] **Step 5: Refactor `PaymentService` to use the builder**

In `app/Services/Payments/PaymentService.php`:

Add import near the top (with the other `use` lines):
```php
use App\Services\Documents\SnapshotBuilder;
```

Replace the constructor:
```php
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly SnapshotBuilder $snapshots,
    ) {}
```

In `issueReceipt()`, change the snapshot line to:
```php
                'snapshot' => $this->snapshots->forTransaction($transaction),
```

Delete the entire private `snapshot()` method (the `/** Freeze client + line … */`
method and its body) — it now lives in `SnapshotBuilder`.

- [ ] **Step 6: Run the Payments suite — verify no regression**

Run: `docker compose exec laravel.test php artisan test --filter='PaymentTest|PaymentWebhookTest'`
Expected: PASS (receipt still issued; snapshot now carries the extra keys).

- [ ] **Step 7: Commit**

```bash
git add app/Services/Documents/SnapshotBuilder.php tests/Feature/SnapshotBuilderTest.php app/Services/Payments/PaymentService.php
git commit -m "refactor(documents): extract SnapshotBuilder, add warranty/next-service/business"
```

---

## Task 3: DocumentService — lazy invoice minting

**Files:**
- Create: `app/Services/Documents/DocumentService.php`
- Test: `tests/Feature/InvoiceGenerationTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/InvoiceGenerationTest.php`:

```php
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
```

- [ ] **Step 2: Run it — verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=InvoiceGenerationTest`
Expected: FAIL — `Class "App\Services\Documents\DocumentService" not found`.

- [ ] **Step 3: Create `app/Services/Documents/DocumentService.php`**

```php
<?php

namespace App\Services\Documents;

use App\Models\Invoice;
use App\Models\Transaction;

final class DocumentService
{
    public function __construct(private readonly SnapshotBuilder $snapshots) {}

    /**
     * Lazily mint one Invoice per transaction, freezing a snapshot at first
     * view/download. Idempotent — repeat calls return the same record.
     */
    public function invoiceFor(Transaction $transaction): Invoice
    {
        return Invoice::firstOrCreate(
            ['transaction_id' => $transaction->id],
            [
                'number' => $this->nextInvoiceNumber(),
                'amount' => $transaction->amount,
                'snapshot' => $this->snapshots->forTransaction($transaction),
            ],
        );
    }

    /** INV-YYYYMMDD-NNN — daily sequence, mirrors RCP/TXN numbering. */
    private function nextInvoiceNumber(): string
    {
        $prefix = 'INV-'.now()->format('Ymd').'-';
        $last = Invoice::where('number', 'like', $prefix.'%')->orderByDesc('number')->value('number');
        $n = $last ? ((int) substr($last, -3)) + 1 : 1;

        return $prefix.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }
}
```

- [ ] **Step 4: Run it — verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=InvoiceGenerationTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Documents/DocumentService.php tests/Feature/InvoiceGenerationTest.php
git commit -m "feat(documents): lazy invoice minting (INV daily sequence)"
```

---

## Task 4: Blade templates (dompdf-safe)

Table-based layout, no flexbox/Tailwind (dompdf is CSS 2.1 only). No emoji (DejaVu has no
glyphs). Renders defensively from the snapshot.

**Files:**
- Create: `resources/views/documents/layout.blade.php`
- Create: `resources/views/documents/receipt.blade.php`
- Create: `resources/views/documents/invoice.blade.php`

- [ ] **Step 1: Create `resources/views/documents/layout.blade.php`**

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; color: #1f2937; font-size: 12px; background: #f3f4f6; }
        .doc { width: 480px; margin: 24px auto; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 28px; }
        .head { text-align: center; border-bottom: 2px solid #0f1f3d; padding-bottom: 14px; margin-bottom: 14px; }
        .co { font-size: 18px; font-weight: 700; color: #0f1f3d; }
        .co-sub { font-size: 11px; color: #6b7280; margin-top: 3px; }
        .kind { font-size: 15px; font-weight: 700; margin-top: 10px; letter-spacing: .5px; color: {{ $accent ?? '#1e3a8a' }}; }
        table.kv { width: 100%; border-collapse: collapse; }
        table.kv td { padding: 3px 0; vertical-align: top; }
        table.kv td.k { color: #6b7280; }
        table.kv td.v { text-align: right; font-weight: 600; }
        .mono { font-family: 'DejaVu Sans Mono', monospace; }
        hr { border: none; border-top: 1px dashed #d1d5db; margin: 12px 0; }
        .sec-label { font-size: 10px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 6px; }
        .line { background: #f9fafb; border-radius: 7px; padding: 10px 12px; margin-bottom: 8px; }
        .line-title { font-weight: 700; margin-bottom: 4px; }
        .total { border-radius: 8px; padding: 12px; margin-top: 4px; }
        .discount { color: #16a34a; }
        .pill { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 700; background: #fef3c7; color: #92400e; }
        .foot { text-align: center; margin-top: 12px; font-size: 11px; color: #9ca3af; }
    </style>
</head>
<body>
    <div class="doc">
        <div class="head">
            <div class="co">{{ $snapshot['business']['name'] ?? config('business.name') }}</div>
            <div class="co-sub">{{ $snapshot['business']['address'] ?? '' }} · {{ $snapshot['business']['phone'] ?? '' }}</div>
            <div class="kind">@yield('kind')</div>
        </div>
        @yield('body')
    </div>
</body>
</html>
```

- [ ] **Step 2: Create `resources/views/documents/receipt.blade.php`**

```blade
@extends('documents.layout', ['accent' => '#1e3a8a'])

@section('kind', 'OFFICIAL RECEIPT')

@section('body')
    @php
        $s = $snapshot;
        $money = fn ($v) => 'RM ' . number_format((float) $v, 2);
        $date = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d M Y') : '—';
    @endphp

    <table class="kv">
        <tr><td class="k">Receipt No.</td><td class="v mono">{{ $number }}</td></tr>
        <tr><td class="k">Date</td><td class="v">{{ $issuedAt->format('d M Y, h:i A') }}</td></tr>
        <tr><td class="k">Payment</td><td class="v">{{ $s['method'] }}</td></tr>
        <tr><td class="k">Txn ID</td><td class="v mono">{{ $s['txn_id'] }}</td></tr>
    </table>
    <hr>
    <table class="kv">
        <tr><td class="k">Client</td><td class="v">{{ $s['client']['name'] }}</td></tr>
        <tr><td class="k">Phone</td><td class="v">{{ $s['client']['phone'] }}</td></tr>
        <tr><td class="k">Address</td><td class="v">{{ $s['client']['address'] }}</td></tr>
        <tr><td class="k">Serial No.</td><td class="v mono">{{ $s['client']['serial_no'] }}</td></tr>
        @if (!empty($s['warranty_months']))
            <tr><td class="k">Warranty</td><td class="v">{{ $s['warranty_months'] }} Months (expires {{ $date($s['warranty_end']) }})</td></tr>
        @endif
    </table>
    <hr>
    <div class="sec-label">Services Performed</div>
    @foreach ($s['lines'] as $i => $l)
        <div class="line">
            <div class="line-title">{{ $i + 1 }}. {{ $l['service_type'] }}@if ($l['unit_type'] || $l['gas_option']) — {{ $l['unit_type'] ?: $l['gas_option'] }}@endif</div>
            <table class="kv">
                <tr><td class="k">Units</td><td class="v">{{ $l['units'] }}</td></tr>
                <tr><td class="k">Rate</td><td class="v">{{ $money($l['rate']) }} / unit</td></tr>
                <tr><td class="k">Subtotal</td><td class="v">{{ $money((float) $l['rate'] * (int) $l['units']) }}</td></tr>
                @if ((float) $l['discount'] > 0)
                    <tr><td class="k">Discount</td><td class="v discount">- {{ $money($l['discount']) }}</td></tr>
                @endif
                <tr><td class="k"><strong>Service Total</strong></td><td class="v">{{ $money($l['subtotal']) }}</td></tr>
                @if (!empty($l['next_service_date']))
                    <tr><td class="k">Next Service</td><td class="v">{{ $date($l['next_service_date']) }}</td></tr>
                @endif
                @if (!empty($l['repair_desc']))
                    <tr><td class="k">Details</td><td class="v">{{ $l['repair_desc'] }}</td></tr>
                @endif
            </table>
        </div>
    @endforeach
    <hr>
    <div class="total" style="background:#0f1f3d">
        <table style="width:100%">
            <tr>
                <td style="color:#fff;font-weight:700;font-size:11px">TOTAL PAID</td>
                <td style="color:#fff;font-weight:800;font-size:20px;text-align:right">{{ $money($s['total_amount']) }}</td>
            </tr>
        </table>
    </div>
    <div class="foot">Thank you for trusting {{ $s['business']['name'] }}.</div>
@endsection
```

- [ ] **Step 3: Create `resources/views/documents/invoice.blade.php`**

```blade
@extends('documents.layout', ['accent' => '#4338ca'])

@section('kind', 'INVOICE')

@section('body')
    @php
        $s = $snapshot;
        $money = fn ($v) => 'RM ' . number_format((float) $v, 2);
    @endphp

    <table class="kv">
        <tr><td class="k">Invoice No.</td><td class="v mono">{{ $number }}</td></tr>
        <tr><td class="k">Invoice Date</td><td class="v">{{ $issuedAt->format('d M Y') }}</td></tr>
        <tr><td class="k">Due Date</td><td class="v">{{ $dueDate->format('d M Y') }}</td></tr>
        <tr><td class="k">Status</td><td class="v"><span class="pill">{{ ucfirst($status) }}</span></td></tr>
    </table>
    <hr>
    <table class="kv">
        <tr><td class="k">Bill To</td><td class="v">{{ $s['client']['name'] }}</td></tr>
        <tr><td class="k">Phone</td><td class="v">{{ $s['client']['phone'] }}</td></tr>
        <tr><td class="k">Address</td><td class="v">{{ $s['client']['address'] }}</td></tr>
        <tr><td class="k">Serial No.</td><td class="v mono">{{ $s['client']['serial_no'] }}</td></tr>
    </table>
    <hr>
    <div class="sec-label">Services</div>
    @foreach ($s['lines'] as $i => $l)
        <div class="line">
            <div class="line-title">{{ $i + 1 }}. {{ $l['service_type'] }}@if ($l['unit_type'] || $l['gas_option']) — {{ $l['unit_type'] ?: $l['gas_option'] }}@endif</div>
            <table class="kv">
                <tr><td class="k">Units</td><td class="v">{{ $l['units'] }}</td></tr>
                <tr><td class="k">Rate</td><td class="v">{{ $money($l['rate']) }} / unit</td></tr>
                <tr><td class="k">Subtotal</td><td class="v">{{ $money((float) $l['rate'] * (int) $l['units']) }}</td></tr>
                @if ((float) $l['discount'] > 0)
                    <tr><td class="k">Discount</td><td class="v discount">- {{ $money($l['discount']) }}</td></tr>
                @endif
                <tr><td class="k"><strong>Total</strong></td><td class="v">{{ $money($l['subtotal']) }}</td></tr>
            </table>
        </div>
    @endforeach
    <hr>
    <div class="total" style="background:#4338ca">
        <table style="width:100%">
            <tr>
                <td style="color:#fff;font-weight:700;font-size:11px">AMOUNT DUE</td>
                <td style="color:#fff;font-weight:800;font-size:20px;text-align:right">{{ $money($s['total_amount']) }}</td>
            </tr>
        </table>
    </div>
    <div class="foot">Payment via {{ $s['method'] }} · {{ $s['business']['phone'] }}</div>
@endsection
```

- [ ] **Step 4: Commit**

```bash
git add resources/views/documents
git commit -m "feat(documents): dompdf-safe invoice + receipt Blade templates"
```

---

## Task 5: DocumentController + routes

**Files:**
- Create: `app/Http/Controllers/DocumentController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/DocumentControllerTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/DocumentControllerTest.php`:

```php
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

    private function transaction(string $status = 'pending'): Transaction
    {
        $client = Client::create(['name' => 'Zainab', 'phone' => '012-3456789', 'address' => 'No. 5, Jalan Maju, KL']);
        $visit = $client->visits()->create(['visit_date' => '2026-06-08', 'warranty_months' => 3, 'total_amount' => 110]);
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
        $txn = $this->transaction();

        $res = $this->actingAs($this->viewer())->get(route('documents.invoice', $txn));

        $res->assertOk();
        $res->assertSee('INV-', false);
        $res->assertSee('Zainab');
    }

    public function test_invoice_pdf_downloads(): void
    {
        $txn = $this->transaction();

        $res = $this->actingAs($this->viewer())->get(route('documents.invoice.pdf', $txn));

        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $res->getContent());
        $this->assertStringContainsString('attachment', $res->headers->get('content-disposition'));
    }

    public function test_receipt_view_renders_for_paid_transaction(): void
    {
        $txn = $this->transaction();
        app(PaymentService::class)->confirmCash($txn); // issues a Receipt

        $res = $this->actingAs($this->viewer())->get(route('documents.receipt', $txn->fresh()));

        $res->assertOk();
        $res->assertSee('RCP-', false);
    }

    public function test_receipt_returns_404_when_unpaid(): void
    {
        $txn = $this->transaction();

        $this->actingAs($this->viewer())
            ->get(route('documents.receipt', $txn))
            ->assertNotFound();
    }

    public function test_receipt_pdf_downloads_for_paid_transaction(): void
    {
        $txn = $this->transaction();
        app(PaymentService::class)->confirmCash($txn);

        $res = $this->actingAs($this->viewer())->get(route('documents.receipt.pdf', $txn->fresh()));

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
```

- [ ] **Step 2: Run it — verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=DocumentControllerTest`
Expected: FAIL — route `documents.invoice` not defined.

- [ ] **Step 3: Create `app/Http/Controllers/DocumentController.php`**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\Documents\DocumentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class DocumentController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    public function invoice(Transaction $transaction): Response
    {
        return response(view('documents.invoice', $this->invoiceData($transaction)));
    }

    public function invoicePdf(Transaction $transaction): Response
    {
        $data = $this->invoiceData($transaction);

        return Pdf::loadView('documents.invoice', $data)->download($data['number'].'.pdf');
    }

    public function receipt(Transaction $transaction): Response
    {
        return response(view('documents.receipt', $this->receiptData($transaction)));
    }

    public function receiptPdf(Transaction $transaction): Response
    {
        $data = $this->receiptData($transaction);

        return Pdf::loadView('documents.receipt', $data)->download($data['number'].'.pdf');
    }

    /** Invoice view-model — mints the Invoice lazily; renders from its frozen snapshot. */
    private function invoiceData(Transaction $transaction): array
    {
        $invoice = $this->documents->invoiceFor($transaction);
        $snapshot = $invoice->snapshot;

        return [
            'snapshot' => $snapshot,
            'number' => $invoice->number,
            'issuedAt' => $invoice->created_at,
            'dueDate' => Carbon::parse($snapshot['visit_date'])->addDays((int) config('business.invoice_due_days')),
            'status' => $transaction->status,
        ];
    }

    /** Receipt view-model — reads the existing Receipt; 404 if the txn is unpaid. */
    private function receiptData(Transaction $transaction): array
    {
        $receipt = $transaction->receipt;
        abort_if($receipt === null, 404);

        return [
            'snapshot' => $receipt->snapshot,
            'number' => $receipt->number,
            'issuedAt' => $receipt->created_at,
        ];
    }
}
```

- [ ] **Step 4: Register routes in `routes/web.php`**

Add the import with the other controller imports at the top:
```php
use App\Http\Controllers\DocumentController;
```

Inside the `Route::middleware('auth')->group(function () { … })` block (after the Payments
routes, before the closing `});`):
```php
    // Documents (module 6) — invoice & receipt view + PDF, gated view_clients (P3).
    Route::middleware('can:view_clients')->group(function () {
        Route::get('documents/invoice/{transaction}', [DocumentController::class, 'invoice'])->name('documents.invoice');
        Route::get('documents/invoice/{transaction}/pdf', [DocumentController::class, 'invoicePdf'])->name('documents.invoice.pdf');
        Route::get('documents/receipt/{transaction}', [DocumentController::class, 'receipt'])->name('documents.receipt');
        Route::get('documents/receipt/{transaction}/pdf', [DocumentController::class, 'receiptPdf'])->name('documents.receipt.pdf');
    });
```

- [ ] **Step 5: Run it — verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=DocumentControllerTest`
Expected: PASS (7 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/DocumentController.php routes/web.php tests/Feature/DocumentControllerTest.php
git commit -m "feat(documents): invoice/receipt view + PDF controller, routes (view_clients)"
```

---

## Task 6: UI wiring (Vue) + build

Plain `<a href>` anchors — these routes return Blade/PDF, not Inertia, so `<Link>` must not
be used. New tab for view; PDF downloads.

**Files:**
- Modify: `resources/js/Pages/ServiceRecords/Show.vue`
- Modify: `resources/js/Pages/Payments/Return.vue`
- Modify: `resources/js/Pages/Clients/Show.vue`

- [ ] **Step 1: `ServiceRecords/Show.vue` — pending (invoice) + paid (receipt) links**

Replace the pending block (currently the `<div v-if="txn && txn.status === 'pending'">…`):
```html
            <div v-if="txn && txn.status === 'pending'" class="flex flex-col gap-3 rounded-ral border border-warn/30 bg-warn-bg px-5 py-4 text-sm text-warn sm:flex-row sm:items-center sm:justify-between">
                <span>Payment pending via {{ txn.method }}.</span>
                <span class="flex flex-wrap items-center gap-3">
                    <a :href="route('documents.invoice', txn.id)" target="_blank" class="font-semibold underline">View invoice</a>
                    <a :href="route('documents.invoice.pdf', txn.id)" class="font-semibold underline">Download PDF</a>
                    <Link
                        v-if="canCollect"
                        :href="route('payments.show', txn.id)"
                        class="inline-block rounded-ral bg-primary px-4 py-2 font-semibold text-white transition hover:bg-primary-600"
                    >
                        Collect payment
                    </Link>
                </span>
            </div>
```

Replace the paid block (currently `<div v-else-if="txn && txn.status === 'paid'">…`):
```html
            <div v-else-if="txn && txn.status === 'paid'" class="flex flex-col gap-3 rounded-ral border border-ok/30 bg-ok-bg px-5 py-4 text-sm text-ok sm:flex-row sm:items-center sm:justify-between">
                <span>Paid via {{ txn.method }}.</span>
                <span class="flex flex-wrap items-center gap-3">
                    <a :href="route('documents.receipt', txn.id)" target="_blank" class="font-semibold underline">View receipt</a>
                    <a :href="route('documents.receipt.pdf', txn.id)" class="font-semibold underline">Download PDF</a>
                </span>
            </div>
```

- [ ] **Step 2: `Payments/Return.vue` — replace the "PDF coming" notice**

Replace the `<div v-if="transaction.receipt" …>` block:
```html
                <div v-if="transaction.receipt" class="mt-4 rounded-ral bg-surface-muted px-4 py-3 text-sm text-ink-soft">
                    <div>Receipt <span class="font-mono font-semibold text-ink">{{ transaction.receipt.number }}</span></div>
                    <div class="mt-2 flex justify-center gap-3">
                        <a :href="route('documents.receipt', transaction.id)" target="_blank" class="font-semibold text-primary hover:text-primary-600">View</a>
                        <a :href="route('documents.receipt.pdf', transaction.id)" class="font-semibold text-primary hover:text-primary-600">Download PDF</a>
                    </div>
                </div>
```

- [ ] **Step 3: `Clients/Show.vue` — per-visit doc link**

Directly after the visit total `<div class="mt-2 flex justify-between border-t …">…</div>`
(inside the `<article>`), add:
```html
                        <div v-if="v.transaction" class="mt-2 text-right text-xs">
                            <a
                                :href="v.transaction.status === 'paid' ? route('documents.receipt', v.transaction.id) : route('documents.invoice', v.transaction.id)"
                                target="_blank"
                                class="font-semibold text-primary hover:text-primary-600"
                            >
                                {{ v.transaction.status === 'paid' ? 'View receipt' : 'View invoice' }} →
                            </a>
                        </div>
```

- [ ] **Step 4: Build assets**

Run: `docker compose exec laravel.test npm run build`
Expected: build completes, no errors.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/ServiceRecords/Show.vue resources/js/Pages/Payments/Return.vue resources/js/Pages/Clients/Show.vue public/build
git commit -m "feat(documents): invoice/receipt view + PDF links on records, payment, client pages"
```

---

## Task 7: Full suite + docs

- [ ] **Step 1: Run the full test suite**

Run: `docker compose exec laravel.test php artisan test`
Expected: all green (72 prior + ~10 new = ~82 tests).

- [ ] **Step 2: Update `docs/STATUS.md`** — move Documents (6) out of Pending into Completed;
  flip the PDF line; bump "Last updated" to session 9.

- [ ] **Step 3: Update `docs/SESSION-LOG.md`** — add a Session 9 entry (Documents module).

- [ ] **Step 4: Commit**

```bash
git add docs/STATUS.md docs/SESSION-LOG.md
git commit -m "docs(documents): status + session-log for module 6"
```

---

## Self-Review (spec coverage)

- Lazy invoice (`firstOrCreate`, INV-YYYYMMDD-NNN) → Task 3. ✓
- Shared Blade as single source for view + PDF → Tasks 4–5. ✓
- Links-only scope (Records/Payments/Clients pages) → Task 6. ✓
- `view_clients` gating; receipt 404 when unpaid; guest redirect → Task 5 tests. ✓
- Snapshot completeness (warranty_months, next_service_date, business) → Task 2. ✓
- `config/business.php` + dompdf dependency → Task 1. ✓
- PDF assertions (`application/pdf`, `%PDF`, attachment) → Task 5 tests. ✓

No placeholders; types consistent (`SnapshotBuilder::forTransaction`,
`DocumentService::invoiceFor`, route names `documents.{invoice,receipt}[.pdf]`).
