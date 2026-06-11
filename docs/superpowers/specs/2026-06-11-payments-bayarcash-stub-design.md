# Module 5 — Payments (BayarCash stub + Cash) — Design

**Date:** 2026-06-11
**Status:** Approved (design); pending implementation plan
**Module:** 04-feature-modules.md §5 · contract from 06-integrations.md §1

## Goal

Collect and verify payment for a `Transaction`. Two methods: **Cash** (manual confirm) and
**BayarCash** (Malaysian FPX/DuitNow QR gateway, redirect/hosted-page flow).

BayarCash is **not yet registered** — no API credentials. Ship a **stub driver** that mimics
the real redirect flow end-to-end (create intent → hosted page → checksum-signed callback →
webhook → mark paid). The real driver is scaffolded behind the same interface so going live
later = fill credentials + flip one env var, with **zero changes to callers**.

## Non-goals (this module)

- PDF rendering of receipts → deferred to Module 6 (Documents). We create the `Receipt`
  **record** now; rendering lands later.
- Invoice generation flow (Module 6).
- Live BayarCash HTTP calls verified against a real account (no credentials yet).

## Architecture — the stable seam

One interface, two drivers, selected by config. This seam is the entire point of the design:
everything downstream of `createIntent` is shared, so the live swap touches no callers.

```
app/Services/Payments/
  Contracts/PaymentGateway.php          interface
  Data/PaymentIntentData.php            DTO (request to gateway)
  Data/PaymentIntentResult.php          DTO (payment_url + gateway_ref)
  Data/CallbackResult.php               DTO (normalized: gateway_ref, order_number, status, verified, raw)
  Support/Checksum.php                  HMAC-SHA256 helper, shared by both drivers
  FakeBayarCashGateway.php              ACTIVE now
  BayarCashGateway.php                  scaffolded, inert until credentials
  PaymentStatus.php                     enum: PENDING | PAID | FAILED
```

### `PaymentGateway` interface

```php
interface PaymentGateway
{
    public function createIntent(PaymentIntentData $data): PaymentIntentResult;
    public function parseCallback(\Illuminate\Http\Request $request): CallbackResult;
}
```

- `createIntent()` — given order number (txn_id), amount, payer details, return_url,
  callback_url, channel → returns `gateway_ref` (intent id) + `payment_url` to redirect to.
- `parseCallback()` — verify checksum, normalize BayarCash status code → internal
  `PaymentStatus`, return `CallbackResult` (with `verified` bool). **Identical for both
  drivers** — same checksum algorithm, so the stub exercises the real verification code.

### Driver selection

`config/services.php`:

```php
'bayarcash' => [
    'driver'     => env('BAYARCASH_DRIVER', 'fake'),     // fake | live
    'api_token'  => env('BAYARCASH_API_TOKEN'),          // PAT, live only
    'api_secret' => env('BAYARCASH_API_SECRET', 'local-stub-secret'),
    'portal_key' => env('BAYARCASH_PORTAL_KEY'),
    'channel'    => env('BAYARCASH_CHANNEL', 5),          // 5 = DuitNow QR
    'base_url'   => env('BAYARCASH_BASE_URL', 'https://console.bayar.cash/api/v3'),
],
```

`PaymentServiceProvider` binds `PaymentGateway::class` → `FakeBayarCashGateway` when
`driver=fake`, else `BayarCashGateway`. `BayarCashGateway` throws a clear
`"BayarCash credentials not configured"` if instantiated without token/portal_key.

### `BayarCashGateway` (the "prepare for real" piece)

Written against BayarCash v3 API shape:
- `createIntent()` → `POST {base_url}/payment-intents` (Bearer PAT) with `portal_key`,
  `order_number`, `amount`, `payer_name`, `payer_email`, `payer_telephone_number`,
  `payment_channel`, `return_url`, `callback_url`, `checksum`. Returns `{ id, url }`.
- `parseCallback()` → recompute checksum from callback fields, compare, map status code.
- **TODO markers** for exact field names + status-code map — confirmed against BayarCash docs
  at integration time. Code path is complete; only those constants are provisional.

## Checksum

`Support/Checksum.php` — `make(array $fields, string $secret): string` =
`hash_hmac('sha256', implode('|', $orderedValues), $secret)`, and
`verify(array $fields, string $given, string $secret): bool` using `hash_equals`.
The stub signs its simulated callbacks with `api_secret`, so webhook verification runs for
real in dev and in tests. Exact field ordering centralized here (one place to match real docs).

## Flow (stub)

1. `ServiceVisitController@store` (existing) → after creating visit + `Transaction(pending)`,
   redirect to `payments.show` ("proceed to payment", per Module 4).
2. **Payment page** `GET payments/{transaction}` (gated `collect_payment`): shows amount +
   two methods — **Cash** and **DuitNow QR (BayarCash)**. Skipped/redirects to `return` view
   if already paid.
3. **Cash** → `POST payments/{transaction}/cash` → `PaymentService::confirmCash()` → status
   `paid`, `paid_at=now`, `method=Cash`, create Receipt. Redirect to `payments.return` (or txn
   show) with flash.
4. **Gateway** → `POST payments/{transaction}/pay` → `gateway->createIntent()`, persist
   `gateway_ref`, `method='DuitNow QR'`, then `Inertia::location($payment_url)` (full-page
   redirect to hosted page).
5. **Stub hosted page** `GET /dev/bayarcash/{ref}` — standalone blade (NOT Inertia/AdminLayout)
   mimicking BayarCash: merchant header, order ref, amount, **Simulate Paid** / **Simulate
   Failed** buttons. Registered only when `driver=fake`.
6. **Simulate** `POST /dev/bayarcash/{ref}/simulate` → server builds a checksum-signed,
   BayarCash-shaped payload for the chosen status → forwards to the shared callback handler →
   redirects browser to the txn's `return_url`.
7. **Webhook** `POST /webhooks/bayarcash` (public, CSRF-exempt, signature-verified):
   `PaymentWebhookController@handle` → `gateway->parseCallback()` → if `!verified` return 403
   (no state change) → `HandleGatewayCallback` action: row-lock txn, **idempotent** (already
   `paid` → 200 no-op), on `PAID` set paid + Receipt, on `FAILED` set `failed`. Return 200.
8. **Return** `GET payments/{transaction}/return` (auth) → result page reads current txn status
   (paid/failed/pending). The **callback is the source of truth**, not the return redirect.

Both the simulate endpoint and the real webhook funnel through one shared action
(`HandleGatewayCallback`), so feature tests POST signed payloads straight to
`/webhooks/bayarcash` and exercise the production path.

## Receipt (on paid, both methods)

Create `Receipt`:
- `number` = `RCP-YYYYMMDD-NNN` — daily sequence, mirrors existing `TXN-YYYYMMDD-NNN` generation.
- `amount` = transaction amount.
- `snapshot` (array) = client (name, serial, phone), visit lines (type/option/units/rate/
  subtotal/discount), totals, method, warranty, next-service — frozen for stable reprints.
- Guard: `firstOrCreate` by `transaction_id` → exactly one receipt per transaction.

PDF rendering: Module 6.

## Routes / RBAC

| Method | URI | Name | Gate |
|---|---|---|---|
| GET | `payments/{transaction}` | `payments.show` | `collect_payment` |
| POST | `payments/{transaction}/cash` | `payments.cash` | `collect_payment` |
| POST | `payments/{transaction}/pay` | `payments.pay` | `collect_payment` |
| GET | `payments/{transaction}/return` | `payments.return` | auth |
| POST | `webhooks/bayarcash` | `webhooks.bayarcash` | public (signature) |
| GET | `dev/bayarcash/{ref}` | `dev.bayarcash.show` | stub-only (driver=fake) |
| POST | `dev/bayarcash/{ref}/simulate` | `dev.bayarcash.simulate` | stub-only |

`bootstrap/app.php`: CSRF-exempt `webhooks/*` and `dev/bayarcash/*`.

## Controllers / services

- `PaymentController` — `show`, `cash`, `pay`, `return`. Thin; delegates to `PaymentService`.
- `PaymentWebhookController` — `handle`. Delegates to `HandleGatewayCallback`.
- `StubGatewayController` — `show`, `simulate` (dev/stub only).
- `PaymentService` — `confirmCash(Transaction)`, `startGateway(Transaction): string` (returns
  payment_url), `issueReceipt(Transaction)`. Wraps DB transactions.
- `HandleGatewayCallback` (action/invokable) — shared idempotent callback processor.
- `ConfirmCashRequest` — validates method, prevents re-pay of already-paid txn.

## Vue pages (Inertia, AdminLayout)

- `Payments/Show.vue` — amount card, two method buttons (Cash confirm modal; Pay → redirect),
  txn summary.
- `Payments/Return.vue` — result (paid ✓ / failed ✗ / pending) + receipt link (record only for
  now) + back to record.
- Nav: reach payment from Service Records show/index (pending txns get a "Collect payment"
  link, gated `collect_payment`).
- Stub hosted page is a **blade** (`resources/views/dev/bayarcash.blade.php`), not Vue — it
  represents the external gateway.

## Error / edge handling

- Already-paid txn: `show`/`cash`/`pay` short-circuit to `return` (no double charge).
- Invalid checksum: webhook returns 403, no state change.
- Duplicate webhook delivery: idempotent via row lock + status check → one Receipt.
- Failed status: txn `failed`, no Receipt; user may retry (`pay` again creates a new intent).
- Amount mismatch (callback amount != txn amount): reject, log, no state change.

## Testing (Postgres — `ilike`/pg parity already required)

Feature:
1. `cash` → paid + Receipt created + flash; 403 when actor lacks `collect_payment`.
2. `pay` → intent created, `gateway_ref` saved, redirect to `payment_url`.
3. webhook valid checksum + PAID → txn paid, paid_at set, Receipt created, 200.
4. webhook invalid checksum → 403, no state change, no Receipt.
5. webhook duplicate delivery → exactly one Receipt (idempotent).
6. webhook FAILED → txn `failed`, no Receipt.
7. amount mismatch → rejected, no change.
8. `return` page renders correct status.

Unit:
- `Checksum::make/verify` round-trip + tamper detection.
- `FakeBayarCashGateway::createIntent` returns stub URL + ref; `parseCallback` verifies + maps
  status.

## Config / env additions

`.env` + `.env.example`: `BAYARCASH_DRIVER=fake`, `BAYARCASH_API_SECRET=local-stub-secret`,
(+ commented `BAYARCASH_API_TOKEN`, `BAYARCASH_PORTAL_KEY`, `BAYARCASH_CHANNEL`,
`BAYARCASH_BASE_URL` for go-live).

## Going live later (the payoff)

1. Register BayarCash, get PAT + portal key + secret.
2. Confirm exact callback field names + status-code map; update `BayarCashGateway` TODOs +
   `Checksum` field order.
3. Set `BAYARCASH_DRIVER=live` + credentials in `.env`. Point BayarCash portal callback at
   `/webhooks/bayarcash`.
4. No controller, route, Vue, Receipt, or webhook changes. Stub routes auto-disable.
