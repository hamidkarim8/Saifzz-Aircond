# Void Paid Service Records + Filters Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let staff void a mistakenly-paid service record (keeps the Transaction/Invoice/Receipt rows for audit, requires a typed reason, hides it from the customer portal, reopens any auto-completed appointment), add a status chip filter to Service Records, and add a custom date-range filter to Transactions.

**Architecture:** `ServiceVisitController::destroy()` branches on the linked Transaction's current status — `pending` keeps today's soft-cancel behavior unchanged, `paid` routes through a new `PaymentService::voidPaid()` that flips status to `void`, stores a reason/actor/timestamp, and reopens a linked appointment if payment had auto-completed it. Nothing is ever hard-deleted. Portal-facing code (`PortalService`, `PortalController`) gets narrow guards to exclude/404 voided (and, per decision, cancelled) records — staff-side document access is untouched. `ReportService::transactions()` gains an optional explicit date range that overrides its period presets.

**Tech Stack:** Laravel 11, Inertia.js + Vue 3, PostgreSQL, SweetAlert2, PHPUnit (run via `docker exec saifzz-aircond-laravel.test-1 php artisan test`).

## Global Constraints

- No hard deletes anywhere in this feature — Transaction/Invoice/Receipt rows are always kept.
- Void requires a non-empty typed reason (`required|string|max:500`); Cancel (pending) requires none — unchanged from today.
- Permission gating for both Cancel and Void stays exactly what `destroy()` already uses (`ServiceVisit::visibleTo($user)`) — no new permission tier.
- Staff-side document access (`DocumentController`) must keep working on voided records — only the customer portal is blocked.
- `Appointment::TRANSITIONS['completed'] = []` makes `completed → pending` illegal through `canTransitionTo()` by design (that machine models the booking flow). The void-triggered revert bypasses it deliberately with a `forceFill`, scoped only inside `PaymentService`, with a comment explaining why.
- Spec source: `docs/superpowers/specs/2026-07-02-void-paid-service-record-design.md`.

---

### Task 1: Transaction schema — void fields

**Files:**
- Create: `database/migrations/2026_07_02_000001_add_void_fields_to_transactions_table.php`
- Modify: `app/Models/Transaction.php`
- Test: `tests/Feature/ServiceVisitVoidTest.php` (new file, first test only in this task)

**Interfaces:**
- Produces: `transactions.void_reason` (text, nullable), `transactions.voided_at` (timestamp, nullable), `transactions.voided_by` (nullable FK → `users.id`). `Transaction::$fillable` includes all three; `voided_at` cast to `datetime`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceVisitVoidTest extends TestCase
{
    use RefreshDatabase;

    public function test_transaction_accepts_void_fields(): void
    {
        $client = Client::create(['name' => 'A', 'phone' => '011-0000000', 'address' => 'KL']);
        $visit = $client->visits()->create(['visit_date' => '2026-07-01', 'warranty_months' => 0, 'total_amount' => 60]);
        $actor = User::factory()->create();

        $txn = $visit->transaction()->create([
            'txn_id' => 'TXN-20260701-001',
            'amount' => 60,
            'method' => 'Cash',
            'status' => 'void',
            'void_reason' => 'Billed by mistake',
            'voided_at' => now(),
            'voided_by' => $actor->id,
        ]);

        $fresh = Transaction::find($txn->id);
        $this->assertSame('Billed by mistake', $fresh->void_reason);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->voided_at);
        $this->assertSame($actor->id, $fresh->voided_by);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=test_transaction_accepts_void_fields`
Expected: FAIL — `void_reason` not in `$fillable` (mass-assignment silently dropped) or column doesn't exist yet.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->text('void_reason')->nullable()->after('paid_at');
            $table->timestamp('voided_at')->nullable()->after('void_reason');
            $table->foreignId('voided_by')->nullable()->after('voided_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['void_reason', 'voided_at']);
        });
    }
};
```

- [ ] **Step 4: Update the model**

In `app/Models/Transaction.php`, replace the `$fillable` array and `casts()`:

```php
    protected $fillable = [
        'txn_id',
        'visit_id',
        'amount',
        'method',
        'status',
        'gateway_ref',
        'paid_at',
        'void_reason',
        'voided_at',
        'voided_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
```

- [ ] **Step 5: Run migration and test**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan migrate`
Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=test_transaction_accepts_void_fields`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_02_000001_add_void_fields_to_transactions_table.php app/Models/Transaction.php tests/Feature/ServiceVisitVoidTest.php
git commit -m "feat: add void fields to transactions table"
```

---

### Task 2: PaymentService::voidPaid()

**Files:**
- Modify: `app/Services/Payments/PaymentService.php`
- Test: `tests/Feature/ServiceVisitVoidTest.php`

**Interfaces:**
- Consumes: `Transaction::$fillable` including `void_reason`/`voided_at`/`voided_by` (Task 1). `Appointment::$fillable` includes `status`. `PaymentService::completeLinkedAppointment()` (existing, `PaymentService.php:52-74`) for the tenant-match pattern being mirrored.
- Produces: `PaymentService::voidPaid(Transaction $transaction, string $reason, User $actor): void` — sets `transaction.status='void'` + reason/timestamp/actor, and reopens a linked appointment (`status: 'completed' → 'pending'`) only if this payment had auto-completed it. Later tasks (3) call this from the controller.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/ServiceVisitVoidTest.php`:

```php
use App\Models\Appointment;
use App\Services\Payments\PaymentService;

    private function boss(): User
    {
        $boss = User::factory()->admin()->create();
        $boss->update(['tenant_id' => $boss->id]);

        return $boss->fresh();
    }

    /** A paid visit (Cash), optionally linked to an appointment. */
    private function paidVisit(User $boss, ?Appointment $appointment = null): \App\Models\ServiceVisit
    {
        $client = Client::create([
            'name' => 'Zainab', 'phone' => '012-345 6789', 'address' => 'KL',
            'tenant_id' => $boss->tenantId(),
        ]);

        $visit = $client->visits()->create([
            'visit_date' => '2026-07-01',
            'warranty_months' => 0,
            'total_amount' => 60,
            'created_by' => $boss->id,
            'tenant_id' => $boss->tenantId(),
            'appointment_id' => $appointment?->id,
        ]);
        $visit->lines()->create([
            'service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted',
            'units' => 1, 'rate' => 60, 'discount' => 0,
        ]);
        $txn = $visit->transaction()->create([
            'txn_id' => 'TXN-20260701-' . str_pad((string) $visit->id, 3, '0', STR_PAD_LEFT),
            'amount' => 60, 'method' => 'Cash', 'status' => 'pending',
        ]);

        app(PaymentService::class)->confirmCash($txn);

        return $visit->fresh(['transaction']);
    }

    public function test_void_paid_sets_status_reason_actor_timestamp(): void
    {
        $boss = $this->boss();
        $visit = $this->paidVisit($boss);

        app(PaymentService::class)->voidPaid($visit->transaction, 'Billed by mistake', $boss);

        $txn = $visit->transaction->fresh();
        $this->assertSame('void', $txn->status);
        $this->assertSame('Billed by mistake', $txn->void_reason);
        $this->assertNotNull($txn->voided_at);
        $this->assertSame($boss->id, $txn->voided_by);
    }

    public function test_void_reopens_appointment_that_was_auto_completed(): void
    {
        $boss = $this->boss();
        $appt = Appointment::create(['datetime' => '2026-07-01 09:00', 'status' => 'pending', 'tenant_id' => $boss->tenantId()]);
        $visit = $this->paidVisit($boss, $appt); // confirmCash completes it
        $this->assertSame('completed', $appt->fresh()->status);

        app(PaymentService::class)->voidPaid($visit->transaction, 'mistaken billing', $boss);

        $this->assertSame('pending', $appt->fresh()->status);
    }

    public function test_void_leaves_appointment_alone_if_not_completed(): void
    {
        $boss = $this->boss();
        $appt = Appointment::create(['datetime' => '2026-07-01 09:00', 'status' => 'cancelled', 'tenant_id' => $boss->tenantId()]);
        $visit = $this->paidVisit($boss, $appt);

        app(PaymentService::class)->voidPaid($visit->transaction, 'mistaken billing', $boss);

        $this->assertSame('cancelled', $appt->fresh()->status);
    }

    public function test_void_without_linked_appointment_is_noop(): void
    {
        $boss = $this->boss();
        $visit = $this->paidVisit($boss);

        app(PaymentService::class)->voidPaid($visit->transaction, 'mistaken billing', $boss);

        $this->assertSame('void', $visit->transaction->fresh()->status);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=ServiceVisitVoidTest`
Expected: FAIL — `voidPaid` method does not exist on `PaymentService`.

- [ ] **Step 3: Implement `voidPaid()`**

In `app/Services/Payments/PaymentService.php`, add `use App\Models\User;` to the imports, then add these two methods (after `completeLinkedAppointment`):

```php
    public function voidPaid(Transaction $transaction, string $reason, User $actor): void
    {
        DB::transaction(function () use ($transaction, $reason, $actor) {
            $transaction->forceFill([
                'status' => 'void',
                'void_reason' => $reason,
                'voided_at' => now(),
                'voided_by' => $actor->id,
            ])->save();

            $this->reopenLinkedAppointment($transaction);
        });
    }

    private function reopenLinkedAppointment(Transaction $transaction): void
    {
        $visit = $transaction->visit()->first();
        if (! $visit || ! $visit->appointment_id) {
            return;
        }

        $appointment = $visit->appointment()->first();
        if (! $appointment || $appointment->status !== 'completed') {
            return;
        }

        // Reached via the visit's own FK; assert same tenant before mutating.
        if ($appointment->tenant_id !== $visit->tenant_id) {
            return;
        }

        // Appointment::TRANSITIONS treats 'completed' as terminal — that state
        // machine models the booking flow. Voiding a payment is a billing
        // correction outside that flow, so we bypass canTransitionTo() here
        // deliberately rather than adding a backward transition to the machine.
        $appointment->forceFill(['status' => 'pending'])->save();
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=ServiceVisitVoidTest`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/Payments/PaymentService.php tests/Feature/ServiceVisitVoidTest.php
git commit -m "feat: add PaymentService::voidPaid with appointment reopen"
```

---

### Task 3: ServiceVisitController::destroy() — cancel vs void branch

**Files:**
- Modify: `app/Http/Controllers/ServiceVisitController.php:286-300`
- Test: `tests/Feature/ServiceVisitVoidTest.php`

**Interfaces:**
- Consumes: `PaymentService::voidPaid(Transaction, string, User)` (Task 2).
- Produces: `DELETE service-records/{serviceRecord}` now accepts an optional `reason` field; branches by the record's transaction status.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/ServiceVisitVoidTest.php`:

```php
    public function test_pending_record_still_cancels_without_reason(): void
    {
        $boss = $this->boss();
        $client = Client::create(['name' => 'A', 'phone' => '011-0000000', 'address' => 'KL', 'tenant_id' => $boss->tenantId()]);
        $visit = $client->visits()->create(['visit_date' => '2026-07-01', 'warranty_months' => 0, 'total_amount' => 60, 'tenant_id' => $boss->tenantId()]);
        $visit->transaction()->create(['txn_id' => 'TXN-20260701-900', 'amount' => 60, 'method' => 'Cash', 'status' => 'pending']);

        $this->actingAs($boss)
            ->delete(route('service-records.destroy', $visit))
            ->assertRedirect(route('service-records.index'));

        $this->assertSame('cancelled', $visit->transaction->fresh()->status);
    }

    public function test_void_requires_reason(): void
    {
        $boss = $this->boss();
        $visit = $this->paidVisit($boss);

        $this->actingAs($boss)
            ->delete(route('service-records.destroy', $visit))
            ->assertSessionHasErrors('reason');

        $this->assertSame('paid', $visit->transaction->fresh()->status);
    }

    public function test_void_via_http_persists_reason(): void
    {
        $boss = $this->boss();
        $visit = $this->paidVisit($boss);

        $this->actingAs($boss)
            ->delete(route('service-records.destroy', $visit), ['reason' => 'Billed by mistake'])
            ->assertRedirect(route('service-records.index'));

        $txn = $visit->transaction->fresh();
        $this->assertSame('void', $txn->status);
        $this->assertSame('Billed by mistake', $txn->void_reason);
        $this->assertSame($boss->id, $txn->voided_by);
    }

    public function test_void_blocked_once_already_void(): void
    {
        $boss = $this->boss();
        $visit = $this->paidVisit($boss);
        $this->actingAs($boss)->delete(route('service-records.destroy', $visit), ['reason' => 'first']);

        $this->actingAs($boss)
            ->delete(route('service-records.destroy', $visit), ['reason' => 'second'])
            ->assertStatus(422);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=ServiceVisitVoidTest`
Expected: FAIL — `test_void_requires_reason` etc. fail because `destroy()` still 422s on any non-pending status unconditionally.

- [ ] **Step 3: Implement the branch**

Add `use App\Services\Payments\PaymentService;` and `use Illuminate\Http\Request;` to the imports at the top of `app/Http/Controllers/ServiceVisitController.php` (check first — the file currently imports `RedirectResponse` but not `Request` or `PaymentService`).

Replace `destroy()` in `app/Http/Controllers/ServiceVisitController.php:286-300`:

```php
    public function destroy(Request $request, ServiceVisit $serviceRecord, PaymentService $payments): RedirectResponse
    {
        abort_unless(
            ServiceVisit::whereKey($serviceRecord->getKey())->visibleTo(request()->user())->exists(),
            403,
        );

        $txn = $serviceRecord->transaction;
        abort_unless($txn && in_array($txn->status, ['pending', 'paid'], true), 422);

        if ($txn->status === 'paid') {
            $data = $request->validate(['reason' => 'required|string|max:500']);
            $payments->voidPaid($txn, $data['reason'], $request->user());

            return redirect()->route('service-records.index')
                ->with('success', 'Record voided.');
        }

        $txn->update(['status' => 'cancelled']);

        return redirect()->route('service-records.index')
            ->with('success', 'Record cancelled.');
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=ServiceVisitVoidTest`
Expected: PASS (all tests in the file so far)

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ServiceVisitController.php tests/Feature/ServiceVisitVoidTest.php
git commit -m "feat: void paid service records via destroy(), cancel unchanged for pending"
```

---

### Task 4: ServiceVisitController::index() — status filter

**Files:**
- Modify: `app/Http/Controllers/ServiceVisitController.php:19-57`
- Test: `tests/Feature/ServiceVisitVoidTest.php`

**Interfaces:**
- Produces: `GET service-records?status=paid|pending|cancelled|void` filters the list; `status` prop returned to Inertia (defaults `'all'`).

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/ServiceVisitVoidTest.php`:

```php
    public function test_index_status_filter_returns_matching_rows_only(): void
    {
        $boss = $this->boss();
        $paid = $this->paidVisit($boss);

        $pendingClient = Client::create(['name' => 'B', 'phone' => '011-1111111', 'address' => 'KL', 'tenant_id' => $boss->tenantId()]);
        $pendingVisit = $pendingClient->visits()->create(['visit_date' => '2026-07-01', 'warranty_months' => 0, 'total_amount' => 40, 'tenant_id' => $boss->tenantId()]);
        $pendingVisit->transaction()->create(['txn_id' => 'TXN-20260701-500', 'amount' => 40, 'method' => 'Cash', 'status' => 'pending']);

        $this->actingAs($boss)
            ->get(route('service-records.index', ['status' => 'paid']))
            ->assertInertia(fn ($page) => $page
                ->where('visits.total', 1)
                ->where('visits.data.0.id', $paid->id));
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=test_index_status_filter_returns_matching_rows_only`
Expected: FAIL — `visits.total` is 2, `status` query param currently ignored.

- [ ] **Step 3: Implement the filter**

In `app/Http/Controllers/ServiceVisitController.php`, inside `index()` (after the `$search` line, around line 21):

```php
        $status  = request()->string('status')->trim()->value();
```

After the existing `if ($search !== '') { ... }` block (around line 44), add:

```php
        if ($status !== '' && $status !== 'all') {
            $query->whereHas('transaction', fn ($t) => $t->where('status', $status));
        }
```

In the `Inertia::render` call at the end of `index()`, add the `status` prop:

```php
        return Inertia::render('ServiceRecords/Index', [
            'visits' => $visits,
            'status' => $status !== '' ? $status : 'all',
        ]);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=test_index_status_filter_returns_matching_rows_only`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ServiceVisitController.php tests/Feature/ServiceVisitVoidTest.php
git commit -m "feat: status filter on service records index"
```

---

### Task 5: Portal receipt 404s once voided

**Files:**
- Modify: `app/Http/Controllers/PortalController.php:101-106`
- Test: `tests/Feature/Portal/PortalReceiptTest.php`

**Interfaces:**
- Consumes: `PaymentService::voidPaid()` (Task 2).
- Produces: `PortalController::authorizeReceipt()` now also 404s when `transaction.status === 'void'`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Portal/PortalReceiptTest.php` (add `use App\Models\User;` and `use App\Services\Payments\PaymentService;` to the imports):

```php
    public function test_voided_transaction_is_404(): void
    {
        [$client, $txn] = $this->clientWithTxn();

        app(PaymentService::class)->voidPaid($txn, 'mistaken billing', User::factory()->create());

        $this->withSession(['portal_client_id' => $client->id])
            ->get(route('portal.receipt', $txn->fresh()))
            ->assertNotFound();
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=test_voided_transaction_is_404`
Expected: FAIL — receipt still renders (200) because `authorizeReceipt()` doesn't check status.

- [ ] **Step 3: Implement the guard**

Replace `authorizeReceipt()` in `app/Http/Controllers/PortalController.php:101-106`:

```php
    private function authorizeReceipt(Request $request, Transaction $transaction): void
    {
        $client = $request->attributes->get('portal_client');

        abort_unless($transaction->visit->client_id === $client->id, 404);
        abort_if($transaction->status === 'void', 404);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=PortalReceiptTest`
Expected: PASS (all tests in the file, including the new one)

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/PortalController.php tests/Feature/Portal/PortalReceiptTest.php
git commit -m "fix: portal receipt 404s once the transaction is voided"
```

---

### Task 6: Portal account list excludes void + cancelled visits

**Files:**
- Modify: `app/Services/Portal/PortalService.php:33-39`
- Test: `tests/Feature/Portal/PortalServiceTest.php`

**Interfaces:**
- Produces: `PortalService::accountFor()` no longer lists visits whose transaction status is `void` or `cancelled`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Portal/PortalServiceTest.php`:

```php
    public function test_account_excludes_void_and_cancelled_visits(): void
    {
        $client = $this->client();

        $paid = $client->visits()->create(['visit_date' => '2026-01-10', 'warranty_months' => 0, 'total_amount' => 60]);
        $paid->transaction()->create(['txn_id' => 'TXN-A', 'amount' => 60, 'method' => 'Cash', 'status' => 'paid']);

        $void = $client->visits()->create(['visit_date' => '2026-02-10', 'warranty_months' => 0, 'total_amount' => 60]);
        $void->transaction()->create(['txn_id' => 'TXN-B', 'amount' => 60, 'method' => 'Cash', 'status' => 'void']);

        $cancelled = $client->visits()->create(['visit_date' => '2026-03-10', 'warranty_months' => 0, 'total_amount' => 60]);
        $cancelled->transaction()->create(['txn_id' => 'TXN-C', 'amount' => 60, 'method' => 'Cash', 'status' => 'cancelled']);

        $account = $this->service()->accountFor($client->fresh());

        $this->assertCount(1, $account['visits']);
        $this->assertSame($paid->id, $account['visits'][0]['id']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=test_account_excludes_void_and_cancelled_visits`
Expected: FAIL — `assertCount(1, ...)` fails, all 3 visits are listed.

- [ ] **Step 3: Implement the exclusion**

In `app/Services/Portal/PortalService.php`, replace the `visits` eager-load constraint in `accountFor()`:

```php
        $client->load([
            'visits' => fn ($q) => $q->latest('visit_date')
                ->whereHas('transaction', fn ($t) => $t->whereNotIn('status', ['void', 'cancelled'])),
            'visits.lines',
            'visits.transaction',
        ]);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=PortalServiceTest`
Expected: PASS (all tests in the file)

- [ ] **Step 5: Commit**

```bash
git add app/Services/Portal/PortalService.php tests/Feature/Portal/PortalServiceTest.php
git commit -m "fix: exclude void/cancelled visits from customer portal history"
```

---

### Task 7: ReportService::transactions() custom date range

**Files:**
- Modify: `app/Services/Reports/ReportService.php:142-199`
- Test: `tests/Feature/ReportServiceTest.php`

**Interfaces:**
- Produces: `ReportService::transactions(string $period, ?int $limit = 50, ?int $technicianId = null, ?int $tenantId = null, ?Carbon $from = null, ?Carbon $to = null): array` — when both `$from`/`$to` are given, they override the `$period` preset. All existing call sites (`TransactionController`, `DashboardController`, `ReportController`) are unaffected since the new params are optional and appended at the end.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/ReportServiceTest.php` (`Carbon` is already imported at the top of the file):

```php
    public function test_transactions_custom_date_range_overrides_period(): void
    {
        $client = Client::create(['name' => 'C', 'phone' => '011-2222222', 'address' => 'KL']);
        $visit1 = $this->visitFor($client, 'Cleaning', '2026-06-05');
        $this->txn($visit1, 100, 'paid', '2026-06-05 10:00:00', 'TXN-1');
        $visit2 = $this->visitFor($client, 'Cleaning', '2026-06-20');
        $this->txn($visit2, 200, 'paid', '2026-06-20 10:00:00', 'TXN-2');

        $rows = $this->service()->transactions(
            'all', null, null, null,
            Carbon::parse('2026-06-01'), Carbon::parse('2026-06-10'),
        );

        $this->assertCount(1, $rows);
        $this->assertSame('TXN-1', $rows[0]['txn_id']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=test_transactions_custom_date_range_overrides_period`
Expected: FAIL — method signature doesn't accept 2 extra args (TypeError/ArgumentCountError), or (once signature is stubbed) both rows return because the range is ignored.

- [ ] **Step 3: Implement the override**

In `app/Services/Reports/ReportService.php`, change the `transactions()` signature and its first line (`ReportService.php:142-144`):

```php
    public function transactions(
        string $period,
        ?int $limit = 50,
        ?int $technicianId = null,
        ?int $tenantId = null,
        ?Carbon $from = null,
        ?Carbon $to = null,
    ): array
    {
        if (! $from || ! $to) {
            [$from, $to] = $this->range($period);
        }
```

(Delete the old `[$from, $to] = $this->range($period);` line — it's replaced by the block above. The rest of the method body is unchanged.)

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=ReportServiceTest`
Expected: PASS (all tests in the file — confirms existing callers still work with the new optional params)

- [ ] **Step 5: Commit**

```bash
git add app/Services/Reports/ReportService.php tests/Feature/ReportServiceTest.php
git commit -m "feat: ReportService::transactions accepts an explicit date range override"
```

---

### Task 8: TransactionController date_from/date_to wiring

**Files:**
- Create: `tests/Feature/TransactionControllerTest.php`
- Modify: `app/Http/Controllers/TransactionController.php`

**Interfaces:**
- Consumes: `ReportService::transactions(..., ?Carbon $from, ?Carbon $to)` (Task 7).
- Produces: `GET transactions?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD` filters the list, overriding `period`. Inertia props gain `dateFrom`/`dateTo` (both `null` unless a valid range was supplied).

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ServiceLine;
use App\Models\ServiceVisit;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionControllerTest extends TestCase
{
    use RefreshDatabase;

    private function boss(): User
    {
        $boss = User::factory()->admin()->create();
        $boss->update(['tenant_id' => $boss->id]);

        return $boss->fresh();
    }

    private function paidTxnOn(string $date, int $tenantId): Transaction
    {
        $client = Client::create(['name' => 'C', 'phone' => '011-0000000', 'address' => 'KL']);
        $visit = ServiceVisit::create([
            'client_id' => $client->id, 'visit_date' => $date, 'warranty_months' => 0,
            'total_amount' => 60, 'tenant_id' => $tenantId,
        ]);
        ServiceLine::create(['visit_id' => $visit->id, 'service_type' => 'Cleaning', 'units' => 1, 'rate' => 60, 'discount' => 0]);

        return Transaction::create([
            'txn_id' => 'TXN-' . str_replace('-', '', $date) . '-001',
            'visit_id' => $visit->id, 'amount' => 60, 'method' => 'Cash',
            'status' => 'paid', 'paid_at' => $date . ' 10:00:00',
        ]);
    }

    public function test_date_range_filters_out_transactions_outside_it(): void
    {
        $boss = $this->boss();
        $inRange = $this->paidTxnOn('2026-06-05', $boss->tenantId());
        $this->paidTxnOn('2026-06-20', $boss->tenantId());

        $this->actingAs($boss)
            ->get(route('transactions.index', ['date_from' => '2026-06-01', 'date_to' => '2026-06-10']))
            ->assertInertia(fn ($page) => $page
                ->has('transactions', 1)
                ->where('transactions.0.txn_id', $inRange->txn_id));
    }

    public function test_date_to_before_date_from_is_rejected(): void
    {
        $this->actingAs($this->boss())
            ->get(route('transactions.index', ['date_from' => '2026-06-10', 'date_to' => '2026-06-01']))
            ->assertSessionHasErrors('date_to');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=TransactionControllerTest`
Expected: FAIL — `test_date_range_filters_out_transactions_outside_it` sees 2 transactions (range ignored); `test_date_to_before_date_from_is_rejected` gets no validation error (query params aren't validated yet).

- [ ] **Step 3: Implement date-range wiring**

Replace `app/Http/Controllers/TransactionController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Services\Reports\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function index(Request $request, ReportService $reports): Response
    {
        $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $user = $request->user();
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $hasRange = $dateFrom && $dateTo;

        $period = in_array($request->query('period'), ReportService::PERIODS, true)
            ? $request->query('period')
            : 'all';

        $techId = $user->seesAllData() ? null : $user->id;
        $tenantId = $user->tenantId();

        $transactions = $reports->transactions(
            $period,
            null,
            $techId,
            $tenantId,
            $hasRange ? Carbon::parse($dateFrom)->startOfDay() : null,
            $hasRange ? Carbon::parse($dateTo)->endOfDay() : null,
        );

        return Inertia::render('Transactions/Index', [
            'transactions' => $transactions,
            'period' => $hasRange ? null : $period,
            'periods' => ReportService::PERIODS,
            'dateFrom' => $hasRange ? $dateFrom : null,
            'dateTo' => $hasRange ? $dateTo : null,
        ]);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=TransactionControllerTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/TransactionController.php tests/Feature/TransactionControllerTest.php
git commit -m "feat: custom date-range query params on transactions index"
```

---

### Task 9: Void status badge color

**Files:**
- Modify: `resources/js/lib/badges.js`

**Interfaces:**
- Produces: `statusVariant('void')` / `statusVariant('Void')` → `'gray'` (distinct from green/paid, amber/pending, red/cancelled-failed).

- [ ] **Step 1: Add the mapping**

In `resources/js/lib/badges.js`, update `STATUS_VARIANT`:

```js
export const STATUS_VARIANT = {
    Paid: 'green', Confirmed: 'green', Done: 'green', Active: 'green',
    Pending: 'amber',
    Failed: 'red', Cancelled: 'red',
    Void: 'gray',
};
```

- [ ] **Step 2: Verify no build errors**

Run: `npm run build`
Expected: build succeeds, no output errors.

- [ ] **Step 3: Commit**

```bash
git add resources/js/lib/badges.js
git commit -m "feat: gray badge variant for void status"
```

---

### Task 10: confirmWithReason SweetAlert2 helper

**Files:**
- Modify: `resources/js/lib/swal.js`

**Interfaces:**
- Produces: `confirmWithReason({ title, body, confirmText, inputLabel? }): Promise<string|null>` — resolves to the trimmed typed reason if confirmed, `null` if cancelled or left blank. Consumed by Tasks 11–12.

- [ ] **Step 1: Add the helper**

Append to `resources/js/lib/swal.js`:

```js
// Danger-styled confirm with a required reason textarea.
// Returns Promise<string|null> — the trimmed reason if confirmed, else null.
export async function confirmWithReason({ title, body = '', confirmText = 'Confirm', inputLabel = 'Reason' }) {
    const r = await base.fire({
        icon: 'warning',
        title,
        html: body,
        input: 'textarea',
        inputLabel,
        inputPlaceholder: 'Why?',
        inputValidator: (value) => (!value || !value.trim() ? 'A reason is required.' : undefined),
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: 'Cancel',
        customClass: {
            popup: 'rounded-rax font-sans',
            title: 'text-navy-800 text-lg font-bold',
            htmlContainer: 'text-ink-soft text-sm',
            confirmButton:
                'inline-flex items-center gap-2 rounded-ra bg-danger px-4 py-2.5 text-sm font-semibold text-white hover:opacity-90',
            cancelButton:
                'inline-flex items-center gap-2 rounded-ra border border-line bg-surface px-4 py-2.5 text-sm font-semibold text-ink hover:bg-surface-muted',
            actions: 'gap-3',
        },
    });
    return r.isConfirmed ? r.value.trim() : null;
}
```

- [ ] **Step 2: Verify no build errors**

Run: `npm run build`
Expected: build succeeds.

- [ ] **Step 3: Commit**

```bash
git add resources/js/lib/swal.js
git commit -m "feat: confirmWithReason SweetAlert2 helper for destructive+reason actions"
```

---

### Task 11: Void button on ServiceRecords/Show.vue

**Files:**
- Modify: `resources/js/Pages/ServiceRecords/Show.vue`

**Interfaces:**
- Consumes: `confirmWithReason` (Task 10).

- [ ] **Step 1: Import the helper and add voidRecord()**

In `resources/js/Pages/ServiceRecords/Show.vue`, change the import line (currently `import { confirmAction } from '@/lib/swal';`):

```js
import { confirmAction, confirmWithReason } from '@/lib/swal';
```

Add, right after the existing `cancelRecord` function (after line 57):

```js
const voidRecord = async () => {
    const reason = await confirmWithReason({
        title: 'Void this paid record?',
        body: 'This reverses the payment. The invoice/receipt stay on file for your records, but the record leaves the customer portal. A linked appointment reopens if it was auto-completed.',
        confirmText: 'Void record',
    });
    if (!reason) return;
    router.delete(route('service-records.destroy', props.visit.id), { data: { reason }, preserveScroll: true });
};
```

- [ ] **Step 2: Add the button to the paid branch**

In the template, inside the `v-else-if="txn && txn.status === 'paid'"` block (around line 156-173), add a Void button next to the Google Review button:

```html
            <div v-else-if="txn && txn.status === 'paid'" class="overflow-hidden rounded-ral border border-ok/40 bg-ok-bg shadow-card">
                <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-2">
                        <Badge variant="green">Paid</Badge>
                        <span class="text-sm text-ok">Paid via {{ txn.method }}.</span>
                    </div>
                    <span class="flex flex-wrap items-center gap-3">
                        <a :href="route('documents.receipt', txn.id)" target="_blank" class="text-sm font-semibold text-ok underline hover:text-ok/80 transition">View receipt</a>
                        <a :href="route('documents.receipt.pdf', txn.id)" class="text-sm font-semibold text-ok underline hover:text-ok/80 transition">Download PDF</a>
                        <button
                            v-if="googleReview.qrUrl"
                            type="button"
                            class="inline-flex items-center rounded-ra border border-ok/50 bg-white px-3 py-1.5 text-sm font-semibold text-ok transition hover:bg-ok/10"
                            @click="showReview = true"
                        >Google Review</button>
                        <button
                            type="button"
                            class="inline-flex items-center rounded-ra border border-danger/40 bg-white px-3 py-1.5 text-sm font-semibold text-danger transition hover:bg-danger/10"
                            @click="voidRecord"
                        >Void record</button>
                    </span>
                </div>
            </div>
```

- [ ] **Step 3: Verify no build errors**

Run: `npm run build`
Expected: build succeeds.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/ServiceRecords/Show.vue
git commit -m "feat: void button on paid service record detail page"
```

---

### Task 12: Void button + status chips on ServiceRecords/Index.vue

**Files:**
- Modify: `resources/js/Pages/ServiceRecords/Index.vue`

**Interfaces:**
- Consumes: `confirmWithReason` (Task 10), `status` Inertia prop (Task 4).

- [ ] **Step 1: Add imports, props, and voidRecord()**

Change the import line:

```js
import { confirmAction, confirmWithReason } from '@/lib/swal';
```

Change `defineProps` (currently `defineProps({ visits: Object });`):

```js
const props = defineProps({ visits: Object, status: { type: String, default: 'all' } });
```

Add, right after the existing `cancelRecord` function (after line 27):

```js
const STATUSES = ['all', 'paid', 'pending', 'cancelled', 'void'];

const setStatus = (s) => {
    router.get(route('service-records.index'), { status: s }, { preserveState: true, replace: true });
};

const voidRecord = async (row) => {
    const reason = await confirmWithReason({
        title: 'Void this paid record?',
        body: 'Reverses the payment and removes it from the customer portal. Notes stay on file for audit.',
        confirmText: 'Void record',
    });
    if (!reason) return;
    router.delete(route('service-records.destroy', row.id), { data: { reason }, preserveScroll: true });
};
```

- [ ] **Step 2: Add the status chip bar and wire filterParams into DataTable**

In the template, add a `#filters` slot to the `<DataTable>` (it already supports one — see `resources/js/Components/DataTable.vue:104`) and pass `filter-params`:

```html
        <DataTable
            mode="server"
            route-name="service-records.index"
            :rows="visits.data"
            :pagination="visits"
            :columns="columns"
            :filter-params="{ status: props.status }"
            searchable
            search-placeholder="Search client, serial or txn…"
        >
            <template #filters>
                <div class="flex items-center gap-1">
                    <span class="mr-1 text-xs font-semibold text-ink-muted">Status</span>
                    <button
                        v-for="s in STATUSES"
                        :key="s"
                        class="rounded-ra px-2.5 py-1 text-xs font-semibold capitalize transition"
                        :class="props.status === s
                            ? 'bg-primary text-white shadow-card'
                            : 'border border-line bg-surface text-ink-soft hover:bg-surface-muted hover:text-ink'"
                        @click="setStatus(s)"
                    >
                        {{ s === 'all' ? 'All' : s }}
                    </button>
                </div>
            </template>

            <!-- Date / Time -->
```

(This inserts the `#filters` slot as the first child of `<DataTable>`, right before the existing `<!-- Date / Time -->` cell-template comment; all other existing templates inside `<DataTable>` stay exactly as they are.)

- [ ] **Step 3: Add the Void button to row actions**

In the `#cell-actions` template (lines 126-149), add a Void button next to the existing Cancel button:

```html
            <!-- Actions -->
            <template #cell-actions="{ row }">
                <div class="flex items-center justify-end gap-2 whitespace-nowrap">
                    <Link
                        :href="route('service-records.show', row.id)"
                        class="rounded-ra px-3 py-1.5 text-xs font-medium text-primary shadow-card hover:bg-surface-muted transition"
                    >
                        View
                    </Link>
                    <Link
                        v-if="row.transaction?.status === 'pending'"
                        :href="route('service-records.edit', row.id)"
                        class="text-xs font-medium text-ink-soft hover:text-ink transition"
                    >
                        Edit
                    </Link>
                    <button
                        v-if="row.transaction?.status === 'pending'"
                        class="text-xs font-medium text-danger hover:underline transition"
                        @click="cancelRecord(row)"
                    >
                        Cancel
                    </button>
                    <button
                        v-if="row.transaction?.status === 'paid'"
                        class="text-xs font-medium text-danger hover:underline transition"
                        @click="voidRecord(row)"
                    >
                        Void
                    </button>
                </div>
            </template>
```

- [ ] **Step 4: Verify no build errors**

Run: `npm run build`
Expected: build succeeds.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/ServiceRecords/Index.vue
git commit -m "feat: void button and status chip filter on service records list"
```

---

### Task 13: Date-range picker on Transactions/Index.vue

**Files:**
- Modify: `resources/js/Pages/Transactions/Index.vue`

**Interfaces:**
- Consumes: `dateFrom`/`dateTo` Inertia props (Task 8).

- [ ] **Step 1: Add props and range state**

Change `defineProps` (`Transactions/Index.vue:10-14`):

```js
const props = defineProps({
    transactions: { type: Array, default: () => [] },
    period: { type: String, default: 'all' },
    periods: { type: Array, default: () => [] },
    dateFrom: { type: String, default: null },
    dateTo: { type: String, default: null },
});
```

Add, right after `setPeriod` (`Transactions/Index.vue:27-29`):

```js
const rangeFrom = ref(props.dateFrom);
const rangeTo = ref(props.dateTo);

const applyRange = () => {
    if (!rangeFrom.value || !rangeTo.value) return;
    router.get(route('transactions.index'), { date_from: rangeFrom.value, date_to: rangeTo.value }, { preserveState: true, replace: true });
};

const clearRange = () => {
    rangeFrom.value = null;
    rangeTo.value = null;
    setPeriod('all');
};
```

`ref` is already imported at the top of the file (`import { computed, ref } from 'vue';`).

- [ ] **Step 2: Add STATUSES 'void' and date inputs beside the period chips**

Update the `STATUSES` array (`Transactions/Index.vue:33`):

```js
const STATUSES = ['paid', 'pending', 'failed', 'cancelled', 'void'];
```

In the template header block (`Transactions/Index.vue:80-97`), add date inputs after the period-chip `<div>`:

```html
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <h1 class="text-base font-bold text-navy-800">Transactions</h1>
                <div class="flex flex-wrap items-center gap-3">
                    <div class="flex gap-1">
                        <button
                            v-for="p in periods"
                            :key="p"
                            class="rounded-ra px-3 py-1.5 text-xs font-semibold transition"
                            :class="period === p
                                ? 'bg-primary text-white shadow-card'
                                : 'border border-line bg-surface text-ink-soft hover:bg-surface-muted hover:text-ink'"
                            @click="setPeriod(p)"
                        >
                            {{ PERIOD_LABELS[p] ?? p }}
                        </button>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <input v-model="rangeFrom" type="date" class="rounded-ra border-line py-1 text-xs shadow-card focus:border-primary focus:ring-primary" />
                        <span class="text-xs text-ink-muted">to</span>
                        <input v-model="rangeTo" type="date" class="rounded-ra border-line py-1 text-xs shadow-card focus:border-primary focus:ring-primary" />
                        <button
                            class="rounded-ra border border-line bg-surface px-2.5 py-1 text-xs font-semibold text-ink-soft shadow-card transition hover:bg-surface-muted hover:text-ink"
                            @click="applyRange"
                        >Apply</button>
                        <button
                            v-if="dateFrom || dateTo"
                            class="text-xs font-medium text-ink-muted hover:text-ink transition"
                            @click="clearRange"
                        >Clear</button>
                    </div>
                </div>
            </div>
        </template>
```

- [ ] **Step 3: Verify no build errors**

Run: `npm run build`
Expected: build succeeds.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Transactions/Index.vue
git commit -m "feat: custom date-range picker beside period chips on transactions"
```

---

### Task 14: Full verification pass

**Files:** none (verification only)

- [ ] **Step 1: Run the full backend suite**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test`
Expected: all tests pass, no regressions in `ServiceVisitTest`, `AppointmentPaymentCompletionTest`, `PaymentTest`, `ReportServiceTest`, `Portal/*`, `MultiTenantIsolationTest`, plus the new files from Tasks 1-8.

- [ ] **Step 2: Full frontend build**

Run: `npm run build`
Expected: build succeeds with no errors or warnings about unresolved imports.

- [ ] **Step 3: Update the feedback doc statuses**

In `docs/FEEDBACK-02072026.md`, change `FEAT-019`, `CHG-024`, `CHG-025` status from `OPEN` to `TESTING` (per project convention — only Khalid closes items to `CLOSED`).

- [ ] **Step 4: Add a SESSION-LOG entry**

Prepend a new entry to the top of the session list in `docs/SESSION-LOG.md`, following the existing entry format (see the `Session 31` entry for the pattern — Goal / Done / Next):

```markdown
## Session 63 — 2026-07-02 — Void paid service records + filters (FEAT-019/CHG-024/025)

**Goal:** Khalid mistakenly created + fully paid a service record on 1-Jul with no way to undo it. Added a Void action for paid records (Cancel already existed for pending), plus two related filters he asked for in the same conversation.

**Done:**
- `transactions` gains `void_reason`/`voided_at`/`voided_by`; new `status='void'` alongside `pending|paid|failed|cancelled`. No row is ever hard-deleted — Invoice/Receipt stay in the DB for audit.
- `PaymentService::voidPaid()` flips the transaction to void (reason required, actor + timestamp recorded) and reopens a linked appointment if this payment had auto-completed it (deliberate bypass of `Appointment::canTransitionTo` — that machine treats `completed` as terminal by design for the booking flow, void is a billing correction outside it).
- `ServiceVisitController::destroy()` branches: `pending` → unchanged Cancel (no reason), `paid` → new Void (reason required), anything else → 422.
- Portal: voided transactions 404 on receipt access; `PortalService::accountFor()` excludes both `void` and `cancelled` visits from the customer's service history (cancelled was already reachable pre-existing, folded into this change).
- New status chip filter (All/Paid/Pending/Cancelled/Void) on Service Records index; new custom date-range filter (From/To) alongside the existing Today/Week/Month/All chips on Transactions.
- Design: `docs/superpowers/specs/2026-07-02-void-paid-service-record-design.md`. Plan: `docs/superpowers/plans/2026-07-02-void-paid-service-record.md`.
- `FEEDBACK-02072026.md`: FEAT-019/CHG-024/CHG-025 OPEN → TESTING (only Khalid closes to DONE).

**Next:** push for Khalid to test; once confirmed, close out.
```

- [ ] **Step 5: Commit**

```bash
git add docs/FEEDBACK-02072026.md docs/SESSION-LOG.md
git commit -m "docs: FEAT-019/CHG-024/CHG-025 code-complete, ready for Khalid to test"
```
