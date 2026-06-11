# Module 10 — Client Portal — Design

**Date:** 2026-06-11
**Status:** Approved (brainstorming) — ready for implementation plan
**Depends on:** Modules 2 (Clients → `clients.serial_no`/`phone`), 4 (Service Records →
`service_visits.warranty_end`, `service_lines.next_service_date`), 5 (Payments →
`transactions.status`, `receipts`), 6 (Documents → receipt view/PDF renderer, reused).

---

## Goal

Public, unauthenticated, serial-gated **read-only** self-service for the end client. After
entering their serial plus the last 4 digits of the phone on file, the client sees their header,
the next recommended service date, their full service history with per-visit warranty status, and
can download receipts. Mobile-first. This sits **outside** the staff RBAC system (rule P5 in
`docs/03`): no login account, no permissions — access is scoped to the matched client only.

User story (`docs/04` §10): *As a client, I scan my sticker, enter 000148, and see my 3 past
services and warranty.*

---

## Decisions (locked in brainstorming)

1. **Two-factor serial gate — not serial-only.** Serials are monotonic and zero-padded from
   `000001` (`Client::booted()`), so a serial-only gate is trivially enumerable: an attacker could
   walk `000001..N` and scrape every client's name, phone, address, and service history. The portal
   therefore requires **serial + the last 4 digits of the phone on file**. The serial comes from the
   remote sticker / QR; the client knows their own phone. This blocks enumeration (the attacker would
   also need each client's phone) at mild friction.
2. **Generic failure, no oracle.** A wrong serial **or** wrong phone-last-4 returns the same generic
   "No matching record" message. The portal never reveals whether a given serial exists, so it can't
   be used as an enumeration oracle even with rate-limiting in place.
3. **Rate-limited authentication.** The `POST /portal` lookup is throttled (`throttle:5,1` —
   5 attempts/minute/IP) to slow brute-forcing of the 4-digit phone factor.
4. **Session, not stateless.** On a successful match the matched `client_id` is stored in the
   session (`portal_client_id`); portal pages require it via middleware. No "remember me" cookie,
   no token in the URL. Logout clears the key.
5. **Receipts only, session-scoped.** The portal can download **receipts** (paid transactions),
   reusing the module-6 renderer. It never exposes invoices or any unpaid/other-client document. Every
   portal document route re-checks that the transaction belongs to the session client.
6. **Reads only — WhatsApp for actions.** The portal writes nothing to the database. "Contact" and
   "Set appointment" are `wa.me` links to the **business** number (`config('business.phone')`),
   pre-filled — consistent with the module-11 v1 messaging approach.

---

## Components

### `App\Services\Portal\PortalService`

Read-only, unit-testable service (mirrors `App\Services\Reminders\*` / `App\Services\Reports\*`).

- `authenticate(string $serial, string $phone4): ?Client` — fetch the client by exact `serial_no`,
  then confirm its digits-only phone ends in `$phone4`. Returns the `Client` on match, else `null`.
  Soft-deleted clients are excluded (default scope). Comparison strips non-digits from the stored
  phone first (`preg_replace('/\D/', '', $phone)`), so formatting (`012-345 6789`) doesn't matter.
- `accountFor(Client $client): array` — the portal view-model:
  - `client` — `serial_no`, `name` (header only; no address exposed beyond what the client already
    knows — see "Privacy" below).
  - `visits` — latest-first, each with its `lines`, `transaction` (id + status only), `visit_date`,
    `warranty_end`, `total_amount`.
  - `next_service_date` — `MAX(service_lines.next_service_date)` across the client's lines, ignoring
    nulls (Repair / Gas lines carry none); `null` when the client has no future-dated service. Mirrors
    the aggregation in `ReminderService::dueList()`.

### `App\Http\Middleware\EnsurePortalClient` (alias `portal.auth`)

Guards the authenticated portal routes. Reads `session('portal_client_id')`; if absent or the client
no longer exists, redirects to `portal.login`. On success, resolves the `Client` and exposes it to the
controller (e.g. via a request attribute) so handlers don't re-query.

### `PortalController`

- `showLogin()` — `GET /portal`. If already session-authed, redirect to `portal.account`; else render
  `Portal/Login`.
- `authenticate(Request)` — `POST /portal`. Validates `serial` (`required|digits:6`) and `phone4`
  (`required|digits:4`). Calls `PortalService::authenticate()`. Hit → `session(['portal_client_id' =>
  $client->id])`, redirect `portal.account`. Miss → back with a generic error (decision 2). Throttled
  by route middleware (decision 3).
- `account()` — `GET /portal/account` (`portal.auth`). Renders `Portal/Show` with
  `PortalService::accountFor()` plus `business` (name + WhatsApp link).
- `logout()` — `POST /portal/logout`. `session()->forget('portal_client_id')`, redirect `portal.login`.
- `receipt(Transaction)` / `receiptPdf(Transaction)` — `GET /portal/receipt/{transaction}[/pdf]`
  (`portal.auth`). Authorization, in order: `abort_unless($transaction->visit->client_id ===
  session('portal_client_id'), 404)` then `abort_if($transaction->receipt === null, 404)` (paid-only).
  Renders via the shared receipt renderer (below). The 404 (not 403) avoids confirming a txn exists for
  another client.

### Documents refactor (shared receipt renderer)

`DocumentController::receiptData()` is currently private. Extract the receipt view-model into
`DocumentService::receiptViewModel(Transaction): array` (returns `snapshot`, `number`, `issuedAt`) and
have **both** `DocumentController` (staff) and `PortalController` call it, so the staff and portal
receipts can't drift. The Blade templates (`resources/views/documents/{layout,receipt}.blade.php`) are
reused unchanged. This is the only change to existing module-6 code.

### Routes (`routes/web.php`, **outside** the `auth` group)

```php
// Client Portal (module 10) — public, serial + phone-last-4 gated (P5). No RBAC.
Route::prefix('portal')->name('portal.')->group(function () {
    Route::get('/', [PortalController::class, 'showLogin'])->name('login');
    Route::post('/', [PortalController::class, 'authenticate'])
        ->middleware('throttle:5,1')->name('authenticate');
    Route::post('logout', [PortalController::class, 'logout'])->name('logout');

    Route::middleware('portal.auth')->group(function () {
        Route::get('account', [PortalController::class, 'account'])->name('account');
        Route::get('receipt/{transaction}', [PortalController::class, 'receipt'])->name('receipt');
        Route::get('receipt/{transaction}/pdf', [PortalController::class, 'receiptPdf'])->name('receipt.pdf');
    });
});
```

The `portal.auth` alias is registered in `bootstrap/app.php` (`$middleware->alias([...])`).

### UI — new `resources/js/Pages/Portal/` (own layout, **not** `AdminLayout`)

`AdminLayout` carries the staff sidebar/nav and assumes `auth.can` — unusable for an unauthenticated
client. The portal gets its own minimal, mobile-first layout.

- **`Portal/PortalLayout.vue`** — narrow centered column, business name header (from props), a logout
  control when on an authed page. No sidebar.
- **`Portal/Login.vue`** — serial input + phone-last-4 input, submit, generic error display, brand
  header. Numeric inputmode for mobile keypads.
- **`Portal/Show.vue`**:
  - Client header — `#serial` + name.
  - **Next recommended service** banner — `next_service_date` (or a "no upcoming service" empty state).
  - Service history — cards reusing the warranty-badge + line-list pattern from `Clients/Show.vue`
    (`warrantyStatus(end)` → Active / Expiring / Expired / No warranty), total per visit.
  - **Download receipt** link per **paid** visit → `portal.receipt.pdf`. No invoice link for unpaid
    visits (decision 5).
  - **WhatsApp** buttons — "Contact us" and "Request appointment" → `wa.me/{business phone}` with
    pre-filled text including the client's serial (decision 6).

Inertia props for portal pages are supplied per-controller (`business.name`, `business.wa`); the portal
does **not** use the global `auth`/`flash` share intended for the staff app.

---

## Privacy

The portal shows the client only data they already own: their name, their service history, warranty,
and receipts. It does **not** echo the full phone or address back (the phone-last-4 is an input, never
displayed). Receipts (which embed the client snapshot) are gated to paid transactions belonging to the
session client. No other client's data is reachable from any portal route.

---

## Tests (`tests/Feature/Portal/`)

**`PortalAuthTest`:**
- correct serial + phone-last-4 → `portal_client_id` set, redirect to `portal.account`.
- wrong phone-last-4 (right serial) → generic error, **no** session set.
- non-existent serial → **same** generic error (no oracle distinguishing "wrong serial" from "wrong
  phone").
- phone-format independence — stored `012-345 6789` matches `phone4 = 6789`.
- validation — non-6-digit serial / non-4-digit phone rejected (422 / back with errors).
- throttle — 6th attempt within a minute → 429.
- already-authed visiting `/portal` → redirect to `portal.account`.

**`PortalAccountTest`:**
- guarded route without session → redirect `portal.login`.
- with session → `Portal/Show` renders with client header, `next_service_date`, and visits carrying
  `warranty_end`.
- `next_service_date` = MAX over lines, ignoring null/Repair lines; `null` when none.
- logout → session cleared, redirect `portal.login`.

**`PortalReceiptTest`:**
- own **paid** txn → 200 receipt view; `/pdf` → 200 `application/pdf`.
- own **unpaid** txn → 404 (no receipt).
- **another client's** paid txn → 404 (cross-client isolation).
- unauthenticated → redirect `portal.login`.

Target: full suite stays green (currently 114 passed / 365 assertions) plus the new cases (~13).

---

## Out of scope (v1)

- Portal-side appointment creation or any DB write (WhatsApp click-to-chat only).
- Invoice / unpaid-document access from the portal (receipts only).
- Email / OTP / password / "remember me" cookie (session only).
- Profile editing or any mutation of client data.
- CAPTCHA (rate-limit + 4-digit second factor cover v1; revisit if abused).
