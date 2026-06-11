# Module 6 — Documents (Invoice & Receipt PDF) — Design

**Date:** 2026-06-11
**Status:** Approved (brainstorming) — ready for implementation plan
**Depends on:** Modules 4 (Service Records) and 5 (Payments). Receipt records are already
created on payment; Invoice records do not yet exist.

---

## Goal

Official document generation: render an **Invoice** (`INV-…`, the unpaid bill) and a
**Receipt** (`RCP-…`, proof of payment) both as an on-screen view **and** a downloadable
PDF, matching the mockup layout (`index.html` lines 668–726). Snapshot data at generation
time so reprints are stable.

This module renders documents from frozen snapshots. It does **not** change how payments or
service records work.

---

## Decisions (locked in brainstorming)

1. **Invoice lifecycle — lazy / on-demand.** An `Invoice` record + `INV-YYYYMMDD-NNN` number
   is minted the first time staff views or downloads the invoice for a transaction
   (`firstOrCreate` keyed on `transaction_id`, mirroring how `Receipt` is issued). No numbers
   are burned for transactions nobody invoices. Receipts continue to be created by the
   Payments module on payment success.
2. **Single source of truth — shared Blade, browser-rendered.** One Blade template per doc
   type. The *view* route returns the Blade as an HTML page (opens in a new tab); the
   *download* route runs the **same** Blade through dompdf. No Vue re-implementation → no
   drift (satisfies `06-integrations.md` §3).
3. **Scope — links only, no index page.** View/Download buttons are added to pages that
   already exist (`ServiceRecords/Show`, `Payments/Return`, `Clients/Show` history). No new
   Documents index route or nav item in v1.
4. **Access gating — `view_clients`.** Documents are read access; anyone who can see client
   data can pull the invoice/receipt. Default technicians qualify; admin implies all.

---

## Architecture

### Dependency + config
- Add `barryvdh/laravel-dompdf` (PDF engine).
- New `config/business.php` — issuer identity (`name`, `address`, `phone`) sourced from
  `BUSINESS_*` env vars. Add `BUSINESS_*` to `.env` and `.env.example`. Defaults match the
  mockup (Saifzz Aircond Services, etc.) so the app renders without extra env setup.

### Snapshot — extract + complete (the one real refactor)
- Move the snapshot builder out of `PaymentService` (private `snapshot()`) into
  `App\Services\Documents\SnapshotBuilder::forTransaction(Transaction $t): array`. Both
  `PaymentService::issueReceipt` and the new invoice path call it → one frozen shape for
  both document types.
- Complete the snapshot to cover everything the mockup renders. Add:
  - visit **`warranty_months`** (the "3 Months" count; `warranty_end` already present),
  - per-line **`next_service_date`**,
  - **`business`** identity (name/address/phone) captured from config at generation time, so
    a later business-detail change does not mutate already-issued documents.
- Blades render **defensively**: a missing snapshot key omits its row, so the handful of
  dev-era receipts issued before this change still render without error.

### Invoice generation — `DocumentService`
- `App\Services\Documents\DocumentService`:
  - `invoiceFor(Transaction $t): Invoice` — `firstOrCreate(['transaction_id' => $t->id], …)`,
    minting `INV-YYYYMMDD-NNN` (daily sequence, same algorithm as `RCP`/`TXN`), storing the
    snapshot + `amount`. One-per-transaction, never regenerated.
- Receipts are **not** created here — they already exist (Payments). `DocumentService` only
  reads `$transaction->receipt`.
- The daily-sequence numbering helper is small; duplicating it for `INV` is acceptable, or it
  may be extracted to a shared helper at the implementer's discretion (low risk either way).

### HTTP — `DocumentController` (all routes gated `can:view_clients`)

| Method & URI | Action | Response |
|---|---|---|
| `GET documents/invoice/{transaction}` | `invoice` | Blade `documents.invoice` as HTML |
| `GET documents/invoice/{transaction}/pdf` | `invoicePdf` | same Blade → dompdf, attachment |
| `GET documents/receipt/{transaction}` | `receipt` | Blade `documents.receipt` as HTML |
| `GET documents/receipt/{transaction}/pdf` | `receiptPdf` | same Blade → dompdf, attachment |

- Routes live inside the existing `auth` group, gated `can:view_clients`.
- Invoice routes render for **any** transaction (it is just the itemised bill); the UI only
  surfaces the invoice while the transaction is unpaid.
- Receipt routes **404** when the transaction has no `Receipt` (i.e. unpaid).
- Controllers render strictly from the **snapshot**, never live models, so reprints are
  stable. (Invoice: build/read snapshot via `DocumentService`. Receipt: read
  `$transaction->receipt->snapshot`.)
- Filename: `{number}.pdf` (e.g. `INV-20260611-001.pdf`).

### Blade templates (dompdf-safe)
- `resources/views/documents/layout.blade.php` — shared shell: business header + a
  self-contained, **table-based** stylesheet (no Tailwind, no flexbox — dompdf supports only
  CSS 2.1). `@yield` the body.
- `resources/views/documents/invoice.blade.php` — invoice number, invoice date, **due date**
  (= `visit_date` + 7 days), status badge (Pending), bill-to block, itemised lines,
  **AMOUNT DUE**, "Payment via {method}".
- `resources/views/documents/receipt.blade.php` — receipt number, date/time, payment method,
  txn id, client block, warranty (`{n} Months (expires {date})`), services performed
  (units / date / rate / subtotal / discount / next service), **TOTAL PAID**, thank-you line.
- Both pull values from the same snapshot array. Layout mirrors the mockup `.rc` card.

### UI wiring (Vue — minimal)
- Document links are plain `<a href>` anchors (the routes return Blade HTML / PDF, **not**
  Inertia responses, so Inertia `<Link>` must not be used). View opens a new tab; PDF
  downloads.
- `ServiceRecords/Show.vue` — transaction paid → **Receipt** (View + PDF); unpaid →
  **Invoice** (View + PDF).
- `Payments/Return.vue` — on success, add View / Download receipt beside the receipt number.
- `Clients/Show.vue` — per service-history row, link to its document (receipt if paid, else
  invoice).
- Whatever data these pages need (transaction id, paid status) is added to the existing
  Inertia props from their controllers.

---

## Error handling
- Receipt view/pdf for an unpaid transaction → `404` (no receipt exists).
- Invoice view/pdf is always permitted (the bill exists as soon as the transaction does).
- Missing optional snapshot keys → the Blade omits that row (no crash on legacy snapshots).
- All routes require auth + `view_clients`; guests redirect to login, unauthorised → 403.

---

## Testing (TDD)
- **`SnapshotBuilderTest`** — output includes `warranty_months`, per-line `next_service_date`,
  and `business` identity; line/total figures match the visit.
- **`InvoiceGenerationTest`** — `invoiceFor` lazily creates one invoice; `INV-YYYYMMDD-NNN`
  daily sequence; idempotent (second call returns the same record, no new number); snapshot
  frozen.
- **`DocumentControllerTest`** (feature, on Postgres):
  - invoice view → `200`, HTML body contains the `INV-` number and client name;
  - invoice pdf → `200`, `Content-Type: application/pdf`, body starts with `%PDF`,
    `Content-Disposition: attachment`;
  - receipt view → `200` for a paid transaction; `404` when unpaid;
  - receipt pdf → `200` pdf for a paid transaction;
  - `view_clients` gate → `403` without the permission;
  - guest → redirect to login.

---

## Out of scope (v1)
- Documents index/list page and search (mockup table) — deferred.
- Editing / voiding / re-numbering documents.
- Emailing or WhatsApp-sharing PDFs (Notifications, module 11) — the PDFs are reusable for it
  later.
- Portal receipt download (module 10) reuses these routes/templates later.
