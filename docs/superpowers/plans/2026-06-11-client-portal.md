# Client Portal (Module 10) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A public, unauthenticated, serial + phone-last-4 gated read-only portal where a client sees their service history, warranty status, next recommended service, and can download receipts.

**Architecture:** A thin read-only `PortalService` (auth match + view-model), a session guard middleware (`EnsurePortalClient`), a `PortalController` (login / authenticate / account / logout / receipt), and a self-contained mobile-first Vue area (`Pages/Portal/*`) on its own layout. The module-6 receipt renderer is extracted into `DocumentService::receiptViewModel()` so staff and portal share one renderer. Nothing in the portal writes to the database; "contact" / "set appointment" are `wa.me` links to the business number.

**Tech Stack:** Laravel 13, Inertia + Vue 3, Tailwind, PostgreSQL, dompdf (existing), PHPUnit feature tests.

**Spec:** `docs/superpowers/specs/2026-06-11-client-portal-design.md`

---

## File Structure

**Create:**
- `app/Services/Portal/PortalService.php` — `authenticate()` + `accountFor()` (read-only).
- `app/Http/Middleware/EnsurePortalClient.php` — session guard (`portal.auth`).
- `app/Http/Controllers/PortalController.php` — all portal HTTP handlers.
- `resources/js/Pages/Portal/PortalLayout.vue` — minimal mobile-first shell.
- `resources/js/Pages/Portal/Login.vue` — serial + phone-last-4 form.
- `resources/js/Pages/Portal/Show.vue` — account page (history, warranty, receipts, WhatsApp).
- `tests/Feature/Portal/PortalServiceTest.php`
- `tests/Feature/Portal/PortalAuthTest.php`
- `tests/Feature/Portal/PortalAccountTest.php`
- `tests/Feature/Portal/PortalReceiptTest.php`

**Modify:**
- `app/Services/Documents/DocumentService.php` — add `receiptViewModel()`.
- `app/Http/Controllers/DocumentController.php` — delegate receipt view-model to the service.
- `bootstrap/app.php` — register `portal.auth` middleware alias.
- `routes/web.php` — add the `portal.*` route group (outside `auth`).
- `docs/STATUS.md`, `docs/SESSION-LOG.md` — module 10 status + session entry.

**Note on patterns (read before starting):** This codebase has **no model factories** except `UserFactory`. Tests build domain models manually: `Client::create([...])` → `$client->visits()->create([...])` → `$visit->lines()->create([...])` → `$visit->transaction()->create([...])`. A Receipt is issued by `app(\App\Services\Payments\PaymentService::class)->confirmCash($txn)`. Follow this exactly (see `tests/Feature/DocumentControllerTest.php`).

---

### Task 1: `PortalService` — authenticate + account view-model

**Files:**
- Create: `app/Services/Portal/PortalService.php`
- Test: `tests/Feature/Portal/PortalServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Portal/PortalServiceTest.php`:

```php
<?php

namespace Tests\Feature\Portal;

use App\Models\Client;
use App\Services\Portal\PortalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): PortalService
    {
        return app(PortalService::class);
    }

    private function client(string $phone = '012-345 6789'): Client
    {
        return Client::create(['name' => 'Zainab', 'phone' => $phone, 'address' => 'No. 5, KL']);
    }

    public function test_authenticate_matches_serial_and_phone_last4(): void
    {
        $client = $this->client();

        $match = $this->service()->authenticate($client->serial_no, '6789');

        $this->assertNotNull($match);
        $this->assertTrue($match->is($client));
    }

    public function test_authenticate_rejects_wrong_phone4(): void
    {
        $client = $this->client();

        $this->assertNull($this->service()->authenticate($client->serial_no, '0000'));
    }

    public function test_authenticate_rejects_unknown_serial(): void
    {
        $this->client();

        $this->assertNull($this->service()->authenticate('999999', '6789'));
    }

    public function test_authenticate_ignores_phone_formatting(): void
    {
        // Stored with spaces and a dash; the last-4 factor is digits-only.
        $client = $this->client('012-345 6789');

        $this->assertNotNull($this->service()->authenticate($client->serial_no, '6789'));
    }

    public function test_account_next_service_is_max_ignoring_nulls(): void
    {
        $client = $this->client();
        $v1 = $client->visits()->create(['visit_date' => '2026-01-10', 'warranty_months' => 3, 'total_amount' => 60]);
        $v1->lines()->create(['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 1, 'rate' => 60, 'discount' => 0, 'next_service_date' => '2026-07-10']);
        $v2 = $client->visits()->create(['visit_date' => '2026-03-01', 'warranty_months' => 0, 'total_amount' => 80]);
        // Repair line carries no next_service_date — must be ignored.
        $v2->lines()->create(['service_type' => 'Repair', 'repair_desc' => 'Fan motor', 'units' => 1, 'rate' => 80, 'discount' => 0, 'next_service_date' => null]);

        $account = $this->service()->accountFor($client->fresh());

        $this->assertSame('2026-07-10', $account['next_service_date']);
        $this->assertCount(2, $account['visits']);
        $this->assertSame('000001', $account['client']['serial_no']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test --filter=PortalServiceTest`
Expected: FAIL — `Class "App\Services\Portal\PortalService" not found`.

- [ ] **Step 3: Write minimal implementation**

Create `app/Services/Portal/PortalService.php`:

```php
<?php

namespace App\Services\Portal;

use App\Models\Client;
use Illuminate\Support\Carbon;

final class PortalService
{
    /**
     * Match a client by exact serial + the last 4 digits of the phone on file.
     * Two-factor gate (spec decision 1) — serials alone are enumerable. Phone is
     * compared digits-only so stored formatting (012-345 6789) is irrelevant.
     */
    public function authenticate(string $serial, string $phone4): ?Client
    {
        $client = Client::where('serial_no', $serial)->first();

        if ($client === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', (string) $client->phone);

        return str_ends_with($digits, $phone4) ? $client : null;
    }

    /**
     * Read-only portal view-model: client header, history (latest first), and the
     * next recommended service date = MAX(line.next_service_date) ignoring nulls
     * (Repair/Gas lines carry none), mirroring ReminderService aggregation.
     */
    public function accountFor(Client $client): array
    {
        $client->load([
            'visits' => fn ($q) => $q->latest('visit_date'),
            'visits.lines',
            'visits.transaction',
        ]);

        $next = $client->visits
            ->flatMap->lines
            ->pluck('next_service_date')
            ->filter()
            ->max();

        return [
            'client' => [
                'serial_no' => $client->serial_no,
                'name' => $client->name,
            ],
            'visits' => $client->visits->map(fn ($v) => [
                'id' => $v->id,
                'visit_date' => $v->visit_date?->toDateString(),
                'warranty_end' => $v->warranty_end?->toDateString(),
                'total_amount' => $v->total_amount,
                'lines' => $v->lines->map(fn ($l) => [
                    'service_type' => $l->service_type,
                    'unit_type' => $l->unit_type,
                    'gas_option' => $l->gas_option,
                    'units' => $l->units,
                    'subtotal' => $l->subtotal,
                ])->values(),
                'transaction' => $v->transaction ? [
                    'id' => $v->transaction->id,
                    'status' => $v->transaction->status,
                ] : null,
            ])->values(),
            'next_service_date' => $next ? Carbon::parse($next)->toDateString() : null,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/sail test --filter=PortalServiceTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Portal/PortalService.php tests/Feature/Portal/PortalServiceTest.php
git commit -m "feat(portal): PortalService — serial+phone-last-4 auth + account view-model"
```

---

### Task 2: Extract shared receipt renderer into `DocumentService`

Removes the receipt view-model from `DocumentController` so staff and portal render the same receipt (spec — "Documents refactor"). No behaviour change for module 6; its tests must stay green.

**Files:**
- Modify: `app/Services/Documents/DocumentService.php`
- Modify: `app/Http/Controllers/DocumentController.php`
- Test: `tests/Feature/DocumentControllerTest.php` (existing — regression only)

- [ ] **Step 1: Add `receiptViewModel()` to `DocumentService`**

In `app/Services/Documents/DocumentService.php`, add these imports and method. Add `use App\Models\Transaction;` already present; add nothing else. Insert the method after `invoiceFor()`:

```php
    /**
     * Receipt view-model from the frozen Receipt snapshot. Shared by the staff
     * DocumentController and the client PortalController so the two can't drift.
     * 404 when the transaction has no Receipt (i.e. it is unpaid).
     */
    public function receiptViewModel(Transaction $transaction): array
    {
        $receipt = $transaction->receipt;

        abort_if($receipt === null, 404);

        return [
            'snapshot' => $receipt->snapshot,
            'number' => $receipt->number,
            'issuedAt' => $receipt->created_at,
        ];
    }
```

- [ ] **Step 2: Point `DocumentController` at the shared method**

In `app/Http/Controllers/DocumentController.php`, replace the private `receiptData()` method body so it delegates (keep the method to avoid touching its callers):

```php
    /** Receipt view-model — delegates to the shared renderer (404 if unpaid). */
    private function receiptData(Transaction $transaction): array
    {
        return $this->documents->receiptViewModel($transaction);
    }
```

- [ ] **Step 3: Run the documents regression suite**

Run: `./vendor/bin/sail test --filter=DocumentControllerTest`
Expected: PASS (all existing cases, incl. `test_receipt_returns_404_when_unpaid`).

- [ ] **Step 4: Commit**

```bash
git add app/Services/Documents/DocumentService.php app/Http/Controllers/DocumentController.php
git commit -m "refactor(documents): extract shared receiptViewModel for portal reuse"
```

---

### Task 3: `EnsurePortalClient` middleware + alias

**Files:**
- Create: `app/Http/Middleware/EnsurePortalClient.php`
- Modify: `bootstrap/app.php`

- [ ] **Step 1: Create the middleware**

Create `app/Http/Middleware/EnsurePortalClient.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Models\Client;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portal session guard (P5). Requires a matched client in the session; resolves
 * the Client and stashes it on the request so handlers don't re-query. No RBAC.
 */
class EnsurePortalClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->session()->get('portal_client_id');
        $client = $id ? Client::find($id) : null;

        if ($client === null) {
            return redirect()->route('portal.login');
        }

        $request->attributes->set('portal_client', $client);

        return $next($request);
    }
}
```

- [ ] **Step 2: Register the alias**

In `bootstrap/app.php`, inside the `->withMiddleware(function (Middleware $middleware): void { ... })` closure, after the existing `$middleware->validateCsrfTokens(...)` call, add:

```php
        $middleware->alias([
            'portal.auth' => \App\Http\Middleware\EnsurePortalClient::class,
        ]);
```

- [ ] **Step 3: Verify the app still boots**

Run: `./vendor/bin/sail artisan route:list --name=portal`
Expected: no routes yet (added next task), but the command runs without error (alias resolves).

- [ ] **Step 4: Commit**

```bash
git add app/Http/Middleware/EnsurePortalClient.php bootstrap/app.php
git commit -m "feat(portal): EnsurePortalClient session guard + portal.auth alias"
```

---

### Task 4: `PortalController` (login / authenticate / account / logout) + routes

**Files:**
- Create: `app/Http/Controllers/PortalController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Portal/PortalAuthTest.php`, `tests/Feature/Portal/PortalAccountTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Portal/PortalAuthTest.php`:

```php
<?php

namespace Tests\Feature\Portal;

use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalAuthTest extends TestCase
{
    use RefreshDatabase;

    private function client(): Client
    {
        return Client::create(['name' => 'Zainab', 'phone' => '012-345 6789', 'address' => 'No. 5, KL']);
    }

    public function test_correct_serial_and_phone4_authenticates(): void
    {
        $client = $this->client();

        $res = $this->post(route('portal.authenticate'), [
            'serial' => $client->serial_no,
            'phone4' => '6789',
        ]);

        $res->assertRedirect(route('portal.account'));
        $this->assertEquals($client->id, session('portal_client_id'));
    }

    public function test_wrong_phone4_is_rejected_without_session(): void
    {
        $client = $this->client();

        $res = $this->from(route('portal.login'))->post(route('portal.authenticate'), [
            'serial' => $client->serial_no,
            'phone4' => '0000',
        ]);

        $res->assertRedirect(route('portal.login'));
        $res->assertSessionHasErrors('serial');
        $this->assertNull(session('portal_client_id'));
    }

    public function test_unknown_serial_gives_same_generic_error(): void
    {
        $this->client();

        $res = $this->from(route('portal.login'))->post(route('portal.authenticate'), [
            'serial' => '999999',
            'phone4' => '6789',
        ]);

        $res->assertSessionHasErrors('serial'); // no oracle — same error as wrong phone
        $this->assertNull(session('portal_client_id'));
    }

    public function test_validation_rejects_malformed_input(): void
    {
        $res = $this->from(route('portal.login'))->post(route('portal.authenticate'), [
            'serial' => '12',     // not 6 digits
            'phone4' => 'abcd',   // not 4 digits
        ]);

        $res->assertSessionHasErrors(['serial', 'phone4']);
    }

    public function test_authentication_is_rate_limited(): void
    {
        $client = $this->client();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('portal.authenticate'), ['serial' => $client->serial_no, 'phone4' => '0000']);
        }

        $res = $this->post(route('portal.authenticate'), ['serial' => $client->serial_no, 'phone4' => '0000']);

        $res->assertStatus(429);
    }

    public function test_already_authed_visiting_login_redirects_to_account(): void
    {
        $client = $this->client();

        $res = $this->withSession(['portal_client_id' => $client->id])->get(route('portal.login'));

        $res->assertRedirect(route('portal.account'));
    }
}
```

Create `tests/Feature/Portal/PortalAccountTest.php`:

```php
<?php

namespace Tests\Feature\Portal;

use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PortalAccountTest extends TestCase
{
    use RefreshDatabase;

    private function clientWithHistory(): Client
    {
        $client = Client::create(['name' => 'Zainab', 'phone' => '012-345 6789', 'address' => 'No. 5, KL']);
        $visit = $client->visits()->create(['visit_date' => '2026-02-01', 'warranty_months' => 3, 'total_amount' => 60]);
        $visit->lines()->create(['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 1, 'rate' => 60, 'discount' => 0, 'next_service_date' => '2026-08-01']);

        return $client;
    }

    public function test_guest_without_session_is_redirected_to_login(): void
    {
        $this->get(route('portal.account'))->assertRedirect(route('portal.login'));
    }

    public function test_authed_client_sees_account_page(): void
    {
        $client = $this->clientWithHistory();

        $res = $this->withSession(['portal_client_id' => $client->id])->get(route('portal.account'));

        $res->assertOk();
        $res->assertInertia(fn (Assert $page) => $page
            ->component('Portal/Show')
            ->where('client.serial_no', $client->serial_no)
            ->where('next_service_date', '2026-08-01')
            ->has('visits', 1)
            ->has('business.wa')
        );
    }

    public function test_logout_clears_session(): void
    {
        $client = $this->clientWithHistory();

        $res = $this->withSession(['portal_client_id' => $client->id])->post(route('portal.logout'));

        $res->assertRedirect(route('portal.login'));
        $this->assertNull(session('portal_client_id'));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/sail test --filter='PortalAuthTest|PortalAccountTest'`
Expected: FAIL — `Route [portal.authenticate] not defined`.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/PortalController.php` (receipt methods added in Task 5):

```php
<?php

namespace App\Http\Controllers;

use App\Services\Portal\PortalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class PortalController extends Controller
{
    public function __construct(private readonly PortalService $portal) {}

    /** Login form (or bounce to the account if already authed). */
    public function showLogin(Request $request): InertiaResponse|RedirectResponse
    {
        if ($request->session()->has('portal_client_id')) {
            return redirect()->route('portal.account');
        }

        return Inertia::render('Portal/Login', ['business' => $this->business()]);
    }

    /** Serial + phone-last-4 lookup. Generic failure — never reveals serial existence. */
    public function authenticate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'serial' => ['required', 'digits:6'],
            'phone4' => ['required', 'digits:4'],
        ]);

        $client = $this->portal->authenticate($data['serial'], $data['phone4']);

        if ($client === null) {
            throw ValidationException::withMessages([
                'serial' => 'No matching record. Check your serial and phone number.',
            ]);
        }

        $request->session()->put('portal_client_id', $client->id);

        return redirect()->route('portal.account');
    }

    /** Read-only account page for the session client. */
    public function account(Request $request): InertiaResponse
    {
        $client = $request->attributes->get('portal_client');

        return Inertia::render('Portal/Show', [
            ...$this->portal->accountFor($client),
            'business' => $this->business(),
        ]);
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget('portal_client_id');

        return redirect()->route('portal.login');
    }

    /** Business identity + WhatsApp number (MY: drop leading 0, prefix 60). */
    protected function business(): array
    {
        $digits = preg_replace('/\D/', '', (string) config('business.phone'));

        return [
            'name' => config('business.name'),
            'wa' => '60'.ltrim($digits, '0'),
        ];
    }
}
```

- [ ] **Step 4: Add the routes**

In `routes/web.php`, add the import near the other controller imports:

```php
use App\Http\Controllers\PortalController;
```

Then add this group **after** the closing `});` of the `Route::middleware('auth')->group(...)` block and **before** the stub-gateway `if (...)` block:

```php
// Client Portal (module 10) — public, serial + phone-last-4 gated (P5). No RBAC.
Route::prefix('portal')->name('portal.')->group(function () {
    Route::get('/', [PortalController::class, 'showLogin'])->name('login');
    Route::post('/', [PortalController::class, 'authenticate'])
        ->middleware('throttle:5,1')->name('authenticate');
    Route::post('logout', [PortalController::class, 'logout'])->name('logout');

    Route::middleware('portal.auth')->group(function () {
        Route::get('account', [PortalController::class, 'account'])->name('account');
    });
});
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/sail test --filter='PortalAuthTest|PortalAccountTest'`
Expected: PASS (9 tests). Note: `Portal/Show` / `Portal/Login` Vue components don't exist yet, but Inertia feature tests assert the component **name** + props server-side and don't render Vue — they pass without the `.vue` files.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/PortalController.php routes/web.php tests/Feature/Portal/PortalAuthTest.php tests/Feature/Portal/PortalAccountTest.php
git commit -m "feat(portal): login/authenticate/account/logout + routes"
```

---

### Task 5: Portal receipt download (session-scoped, paid-only)

**Files:**
- Modify: `app/Http/Controllers/PortalController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Portal/PortalReceiptTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Portal/PortalReceiptTest.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test --filter=PortalReceiptTest`
Expected: FAIL — `Route [portal.receipt] not defined`.

- [ ] **Step 3: Add receipt handlers to `PortalController`**

In `app/Http/Controllers/PortalController.php`, add imports:

```php
use App\Models\Transaction;
use App\Services\Documents\DocumentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;
```

Change the constructor to also inject `DocumentService`:

```php
    public function __construct(
        private readonly PortalService $portal,
        private readonly DocumentService $documents,
    ) {}
```

Add these methods (after `logout()`):

```php
    public function receipt(Request $request, Transaction $transaction): Response
    {
        $this->authorizeReceipt($request, $transaction);

        return response(view('documents.receipt', $this->documents->receiptViewModel($transaction)));
    }

    public function receiptPdf(Request $request, Transaction $transaction): Response
    {
        $this->authorizeReceipt($request, $transaction);
        $data = $this->documents->receiptViewModel($transaction);

        return Pdf::loadView('documents.receipt', $data)->download($data['number'].'.pdf');
    }

    /**
     * The receipt must belong to the session client (cross-client isolation) and
     * be paid (receiptViewModel 404s when unpaid). 404 — not 403 — so the portal
     * never confirms that another client's transaction exists.
     */
    private function authorizeReceipt(Request $request, Transaction $transaction): void
    {
        $client = $request->attributes->get('portal_client');

        abort_unless($transaction->visit->client_id === $client->id, 404);
    }
```

- [ ] **Step 4: Add the receipt routes**

In `routes/web.php`, inside the `Route::middleware('portal.auth')->group(...)` block (next to `account`), add:

```php
        Route::get('receipt/{transaction}', [PortalController::class, 'receipt'])->name('receipt');
        Route::get('receipt/{transaction}/pdf', [PortalController::class, 'receiptPdf'])->name('receipt.pdf');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/sail test --filter=PortalReceiptTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/PortalController.php routes/web.php tests/Feature/Portal/PortalReceiptTest.php
git commit -m "feat(portal): session-scoped, paid-only receipt download"
```

---

### Task 6: Portal Vue pages (layout, login, account)

No JS test harness exists in this project (server-side feature tests cover routing/props). Verification is a clean asset build plus a manual eyeball. **Per project preference, use `npm run dev` (Vite HMR) for the visual check, not a production build.**

**Files:**
- Create: `resources/js/Pages/Portal/PortalLayout.vue`
- Create: `resources/js/Pages/Portal/Login.vue`
- Create: `resources/js/Pages/Portal/Show.vue`

- [ ] **Step 1: Create `PortalLayout.vue`**

Create `resources/js/Pages/Portal/PortalLayout.vue`:

```vue
<script setup>
import { router } from '@inertiajs/vue3';

const props = defineProps({
    business: { type: Object, default: () => ({ name: 'Service Portal' }) },
    showLogout: { type: Boolean, default: false },
});

const logout = () => router.post(route('portal.logout'));
</script>

<template>
    <div class="min-h-screen bg-surface-muted text-ink">
        <header class="bg-navy-900 text-white">
            <div class="mx-auto flex max-w-xl items-center justify-between px-4 py-4">
                <div class="font-bold tracking-tight">{{ business.name }}</div>
                <button
                    v-if="showLogout"
                    type="button"
                    class="text-sm font-semibold text-primary-300 hover:text-white"
                    @click="logout"
                >Sign out</button>
            </div>
        </header>
        <main class="mx-auto max-w-xl px-4 py-6">
            <slot />
        </main>
    </div>
</template>
```

- [ ] **Step 2: Create `Login.vue`**

Create `resources/js/Pages/Portal/Login.vue`:

```vue
<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import PortalLayout from './PortalLayout.vue';

defineProps({ business: Object });

const form = useForm({ serial: '', phone4: '' });

const submit = () => form.post(route('portal.authenticate'));
</script>

<template>
    <Head title="Client Portal" />

    <PortalLayout :business="business">
        <div class="rounded-ral border border-line bg-surface p-6 shadow-card">
            <h1 class="text-lg font-bold text-navy-800">View your service history</h1>
            <p class="mt-1 text-sm text-ink-soft">
                Enter the 6-digit serial from your sticker and the last 4 digits of your phone number.
            </p>

            <form class="mt-5 space-y-4" @submit.prevent="submit">
                <div>
                    <label class="block text-sm font-semibold text-ink-soft" for="serial">Serial number</label>
                    <input
                        id="serial"
                        v-model="form.serial"
                        inputmode="numeric"
                        maxlength="6"
                        placeholder="000148"
                        class="mt-1 w-full rounded-ra border border-line px-3 py-2.5 font-mono tracking-widest focus:border-primary focus:ring-primary"
                    />
                </div>
                <div>
                    <label class="block text-sm font-semibold text-ink-soft" for="phone4">Phone (last 4 digits)</label>
                    <input
                        id="phone4"
                        v-model="form.phone4"
                        inputmode="numeric"
                        maxlength="4"
                        placeholder="6789"
                        class="mt-1 w-full rounded-ra border border-line px-3 py-2.5 font-mono tracking-widest focus:border-primary focus:ring-primary"
                    />
                </div>

                <p v-if="form.errors.serial" class="text-sm font-medium text-danger">{{ form.errors.serial }}</p>
                <p v-else-if="form.errors.phone4" class="text-sm font-medium text-danger">{{ form.errors.phone4 }}</p>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full rounded-ra bg-primary px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60"
                >View my history</button>
            </form>
        </div>
    </PortalLayout>
</template>
```

- [ ] **Step 3: Create `Show.vue`**

Create `resources/js/Pages/Portal/Show.vue`:

```vue
<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import PortalLayout from './PortalLayout.vue';

const props = defineProps({
    client: Object,
    visits: Array,
    next_service_date: { type: String, default: null },
    business: Object,
});

const money = (v) => 'RM ' + Number(v ?? 0).toFixed(2);
const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-MY', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';

// Warranty display (R5) — same semantics as the staff Clients/Show page.
const warrantyStatus = (end) => {
    if (!end) return { label: 'No warranty', cls: 'bg-surface-muted text-ink-soft' };
    const days = Math.ceil((new Date(end) - new Date()) / 86400000);
    if (days < 0) return { label: 'Expired', cls: 'bg-danger-bg text-danger' };
    if (days <= 30) return { label: `Expiring · ${days}d`, cls: 'bg-warn-bg text-warn' };
    return { label: `Active · until ${fmtDate(end)}`, cls: 'bg-ok-bg text-ok' };
};

const waText = encodeURIComponent(`Hi, this is ${props.client.name} (serial ${props.client.serial_no}).`);
const waContact = computed(() => `https://wa.me/${props.business.wa}?text=${waText}`);
const waAppointment = computed(() => `https://wa.me/${props.business.wa}?text=${encodeURIComponent(`Hi, I'd like to set an appointment. ${props.client.name}, serial ${props.client.serial_no}.`)}`);
</script>

<template>
    <Head title="My services" />

    <PortalLayout :business="business" :show-logout="true">
        <!-- Client header -->
        <div class="overflow-hidden rounded-ral border border-line bg-navy-900 p-6 text-white shadow-card">
            <div class="font-mono text-sm tracking-widest text-primary-300">#{{ client.serial_no }}</div>
            <h1 class="mt-1 text-2xl font-bold tracking-tight">{{ client.name }}</h1>
        </div>

        <!-- Next recommended service -->
        <div class="mt-4 rounded-ral border border-line bg-surface p-4 shadow-card">
            <div class="text-xs font-bold uppercase tracking-wide text-ink-soft">Next recommended service</div>
            <div v-if="next_service_date" class="mt-1 text-lg font-bold text-navy-800">{{ fmtDate(next_service_date) }}</div>
            <div v-else class="mt-1 text-sm text-ink-soft">No upcoming service scheduled.</div>
        </div>

        <!-- WhatsApp actions -->
        <div class="mt-4 grid grid-cols-2 gap-3">
            <a :href="waContact" target="_blank" class="rounded-ra bg-wa px-4 py-2.5 text-center text-sm font-semibold text-white transition hover:opacity-90">Contact us</a>
            <a :href="waAppointment" target="_blank" class="rounded-ra border border-line bg-surface px-4 py-2.5 text-center text-sm font-semibold text-navy-800 transition hover:bg-surface-muted">Request appointment</a>
        </div>

        <!-- Service history -->
        <h2 class="mb-3 mt-6 text-sm font-bold uppercase tracking-wide text-ink-soft">Service history</h2>
        <div v-if="visits.length" class="space-y-4">
            <article v-for="v in visits" :key="v.id" class="rounded-ral border border-line bg-surface p-5 shadow-card">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line pb-3">
                    <div class="font-semibold text-ink">{{ fmtDate(v.visit_date) }}</div>
                    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="warrantyStatus(v.warranty_end).cls">{{ warrantyStatus(v.warranty_end).label }}</span>
                </div>
                <ul class="divide-y divide-line">
                    <li v-for="(l, i) in v.lines" :key="i" class="flex items-center justify-between py-2.5 text-sm">
                        <div>
                            <span class="font-medium text-ink">{{ l.service_type }}</span>
                            <span v-if="l.unit_type || l.gas_option" class="text-ink-soft"> · {{ l.unit_type || l.gas_option }}</span>
                            <span class="text-ink-muted"> × {{ l.units }}</span>
                        </div>
                        <span class="font-mono font-semibold text-ink">{{ money(l.subtotal) }}</span>
                    </li>
                </ul>
                <div class="mt-2 flex items-center justify-between border-t border-line pt-3 text-sm">
                    <span class="font-semibold text-ink-soft">Total</span>
                    <span class="font-mono text-base font-bold text-navy-800">{{ money(v.total_amount) }}</span>
                </div>
                <div v-if="v.transaction && v.transaction.status === 'paid'" class="mt-2 text-right text-xs">
                    <a :href="route('portal.receipt.pdf', v.transaction.id)" target="_blank" class="font-semibold text-primary hover:text-primary-600">Download receipt →</a>
                </div>
            </article>
        </div>
        <p v-else class="rounded-ral border border-dashed border-line bg-surface py-10 text-center text-sm text-ink-soft">No service records yet.</p>
    </PortalLayout>
</template>
```

- [ ] **Step 4: Build assets to confirm the pages compile**

Run: `./vendor/bin/sail npm run build`
Expected: build succeeds, no Vue/Vite errors, `Portal/Login` + `Portal/Show` chunks emitted.

- [ ] **Step 5: Manual eyeball (Vite dev server)**

Run (background): `./vendor/bin/sail npm run dev`
Then visit `http://localhost:8000/portal`, enter a seeded client's serial + phone-last-4, and confirm: login → account renders, history cards show warranty badges, paid visits show "Download receipt", WhatsApp buttons open `wa.me`. (Seed/create a client with a known phone first if none exists.)

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Portal/
git commit -m "feat(portal): mobile-first login + account Vue pages"
```

---

### Task 7: Full suite green + status/log update

**Files:**
- Modify: `docs/STATUS.md`
- Modify: `docs/SESSION-LOG.md`

- [ ] **Step 1: Run the entire test suite**

Run: `./vendor/bin/sail test`
Expected: ALL green — previous 114 plus the new Portal tests (PortalService 5, PortalAuth 6, PortalAccount 3, PortalReceipt 5 = 19 new). DocumentControllerTest still passes (Task 2 regression).

- [ ] **Step 2: Update `docs/STATUS.md`**

- Change the header to `session 13`.
- In **At a glance**, add `Client Portal (10)` to the done-modules line.
- Add a `✅ Completed` bullet for **Module 10 — Client Portal** summarizing: public serial + phone-last-4 gate (enumeration-resistant), session guard middleware, read-only account (history + warranty + next-service), session-scoped paid-only receipt download (shared `DocumentService::receiptViewModel`), `wa.me` contact/appointment, own mobile-first layout; full suite count after the run.
- In **⏳ Pending / Next**, remove Client Portal and reorder: **Notifications (11)** ← next, then **Users mgmt screen (1)**. Remove the now-done line 52 ("Public client portal…").

- [ ] **Step 3: Append a `docs/SESSION-LOG.md` entry**

Add a session-13 entry following the existing format: what was built (module 10), key decisions (two-factor gate, generic no-oracle error, receipts-only), and the final test count.

- [ ] **Step 4: Commit**

```bash
git add docs/STATUS.md docs/SESSION-LOG.md
git commit -m "docs: module 10 (Client Portal) status + session-log"
```

---

## Notes for the implementer

- **Sail vs local:** commands above use `./vendor/bin/sail`. If the environment runs PHP/Node directly, drop the `./vendor/bin/sail` prefix (`php artisan ...`, `npm run ...`).
- **No `auth` share on the portal:** portal pages are unauthenticated; pass `business` explicitly via the controller. Do **not** rely on `usePage().props.auth` in `Pages/Portal/*` (it's empty for guests).
- **404 over 403** everywhere in the portal authorization path — never confirm existence of another client's data.
- **Throttle in tests:** the `throttle:5,1` limiter uses the array cache in tests and resets per test method; the rate-limit test must issue all 6 requests within the one method.
