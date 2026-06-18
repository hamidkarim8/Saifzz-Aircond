# Appointment Flow Cluster Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix appointment client-autofill, simplify the status model to pending/completed/cancelled, link a paid service record back to its appointment (auto-complete on payment), and polish the appointments table actions + serial column.

**Architecture:** Laravel 11 + Inertia/Vue 3. Status model collapses via a data migration. A nullable `appointment_id` FK on `service_visits` carries the link from an appointment row through record creation; `PaymentService` completes the linked appointment on both payment-success paths (cash + webhook). Frontend changes in `Appointments/Index.vue`, `AppointmentModal.vue`, `ServiceRecords/Create.vue`.

**Tech Stack:** PHP 8.5, Laravel, PostgreSQL, Inertia, Vue 3, Tailwind, Pest/PHPUnit. Tests run via `docker exec saifzz-aircond-laravel.test-1 php artisan test`.

**Spec:** `docs/superpowers/specs/2026-06-18-appointment-flow-cluster-design.md`

---

## File Structure

- `app/Models/Appointment.php` — STATUSES + TRANSITIONS constants (Task 1), `appointment_id`-side relation N/A.
- `database/migrations/..._collapse_appointment_statuses.php` — data migration (Task 1).
- `database/migrations/..._add_appointment_id_to_service_visits.php` — FK (Task 2).
- `app/Models/ServiceVisit.php` — `appointment_id` fillable + `appointment()` relation (Task 2).
- `app/Http/Controllers/ServiceVisitController.php` — `create()` preset + `store()` persist (Task 3).
- `app/Http/Requests/StoreServiceVisitRequest.php` — `appointment_id` validation (Task 3).
- `app/Services/Payments/PaymentService.php` — `completeLinkedAppointment()` + cash wiring (Task 4).
- `app/Actions/Payments/HandleGatewayCallback.php` — webhook wiring (Task 4).
- `app/Http/Controllers/AppointmentController.php` — stat card + status validation (Task 5).
- `app/Http/Requests/UpdateAppointmentRequest.php` — accept `status` (Task 5).
- `resources/js/Pages/Appointments/Index.vue` — actions, serial column, stat label (Task 6).
- `resources/js/Pages/Appointments/Partials/AppointmentModal.vue` — immediate watch, technician default, status select, cancel redirect (Task 7).
- `resources/js/Pages/ServiceRecords/Create.vue` — `appointment_id` passthrough (Task 8).
- Tests: `tests/Feature/AppointmentTest.php`, new `tests/Feature/AppointmentPaymentCompletionTest.php`, fixtures sweep (Task 9).

---

## Task 1: Collapse appointment status model

**Files:**
- Modify: `app/Models/Appointment.php` (STATUSES + TRANSITIONS)
- Create: `database/migrations/2026_06_18_000020_collapse_appointment_statuses.php`
- Test: `tests/Feature/AppointmentTest.php`

- [ ] **Step 1: Inspect the current appointments status column**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan db:show --table=appointments` (or grep the original create migration).
Confirm `status` is a plain `string` column with NO Postgres enum / check constraint. If a check constraint exists, the migration in Step 4 must `ALTER` it — note the name. Expected: plain varchar default `'pending'`.

- [ ] **Step 2: Update the model constants**

In `app/Models/Appointment.php`, replace the status block:

```php
    /** Status lifecycle: pending → completed / cancelled. */
    public const STATUSES = ['pending', 'completed', 'cancelled'];

    /** Allowed forward transitions per state; completed/cancelled are terminal. */
    public const TRANSITIONS = [
        'pending'   => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];
```

(Keep the existing `canTransitionTo()` method as-is.)

- [ ] **Step 3: Write the failing test**

In `tests/Feature/AppointmentTest.php`, replace any test asserting `confirmed`/`done` transitions with:

```php
public function test_status_transitions_follow_new_model(): void
{
    $appt = $this->makeAppointment(['status' => 'pending']);

    $this->assertTrue($appt->canTransitionTo('completed'));
    $this->assertTrue($appt->canTransitionTo('cancelled'));

    $appt->status = 'completed';
    $this->assertFalse($appt->canTransitionTo('cancelled'));
    $this->assertFalse($appt->canTransitionTo('pending'));

    $appt->status = 'cancelled';
    $this->assertFalse($appt->canTransitionTo('completed'));
}
```

If `makeAppointment` helper does not exist, build the appointment inline with `Appointment::make([...])` (no DB needed for the transition assertions — these are pure model checks).

- [ ] **Step 4: Write the data migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('appointments')->where('status', 'confirmed')->update(['status' => 'pending']);
        DB::table('appointments')->where('status', 'done')->update(['status' => 'completed']);
    }

    public function down(): void
    {
        DB::table('appointments')->where('status', 'completed')->update(['status' => 'done']);
    }
};
```

- [ ] **Step 5: Run the migration + test**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan migrate`
Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=AppointmentTest`
Expected: migration OK; new transition test PASSES. Fix any other AppointmentTest cases still referencing `confirmed`/`done` (statuses, store assertions).

- [ ] **Step 6: Commit**

```bash
git add app/Models/Appointment.php database/migrations/2026_06_18_000020_collapse_appointment_statuses.php tests/Feature/AppointmentTest.php
git commit -m "feat(appointments): collapse status model to pending/completed/cancelled (CHG-004)"
```

---

## Task 2: Add appointment_id link to service_visits

**Files:**
- Create: `database/migrations/2026_06_18_000021_add_appointment_id_to_service_visits.php`
- Modify: `app/Models/ServiceVisit.php`

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_visits', function (Blueprint $table) {
            $table->foreignId('appointment_id')->nullable()->after('id')
                ->constrained('appointments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_visits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('appointment_id');
        });
    }
};
```

- [ ] **Step 2: Add fillable + relation to the model**

In `app/Models/ServiceVisit.php`, add `'appointment_id'` to the `$fillable` array, and add the relation (place near the other `belongsTo` relations):

```php
    public function appointment(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
```

- [ ] **Step 3: Run the migration**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan migrate`
Expected: `appointment_id` column added. No test yet (covered in Task 3/4).

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_06_18_000021_add_appointment_id_to_service_visits.php app/Models/ServiceVisit.php
git commit -m "feat(appointments): add appointment_id FK on service_visits"
```

---

## Task 3: Thread appointment_id through record creation

**Files:**
- Modify: `app/Http/Controllers/ServiceVisitController.php` (`create()` + `store()`)
- Modify: `app/Http/Requests/StoreServiceVisitRequest.php`
- Test: `tests/Feature/AppointmentPaymentCompletionTest.php` (create the file here; payment tests added Task 4)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AppointmentPaymentCompletionTest.php`. Match existing Feature-test fixture style (no factories — build with `Model::create()`; copy a tenant+user+client setup from `tests/Feature/MultiTenantIsolationTest.php`). Add:

```php
public function test_store_persists_valid_appointment_id_on_visit(): void
{
    [$boss, $client] = $this->bossWithClient();           // helper: returns all-data user + own-tenant client
    $appt = $this->makeAppointmentFor($client, $boss);     // helper: pending appointment, same tenant

    $this->actingAs($boss)->post(route('service-records.store'), $this->validVisitPayload($client, [
        'appointment_id' => $appt->id,
    ]))->assertRedirect();

    $this->assertDatabaseHas('service_visits', [
        'client_id'      => $client->id,
        'appointment_id' => $appt->id,
    ]);
}

public function test_store_rejects_cross_tenant_appointment_id(): void
{
    [$boss, $client] = $this->bossWithClient();
    $otherAppt = $this->makeAppointmentForOtherTenant();   // appointment under a different tenant

    $this->actingAs($boss)->post(route('service-records.store'), $this->validVisitPayload($client, [
        'appointment_id' => $otherAppt->id,
    ]))->assertSessionHasErrors('appointment_id');
}
```

Implement the helpers (`bossWithClient`, `makeAppointmentFor`, `makeAppointmentForOtherTenant`, `validVisitPayload`) inline in the test class, mirroring `MultiTenantIsolationTest` setup and a known-good service-record payload (look at `ServiceVisitTest` for the minimal valid line payload using a seeded `ServiceType` + matching `service_fees`).

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=AppointmentPaymentCompletionTest`
Expected: FAIL — `appointment_id` not persisted / not validated.

- [ ] **Step 3: Add validation to StoreServiceVisitRequest**

In `app/Http/Requests/StoreServiceVisitRequest.php` `rules()`, add (tenant-scoped existence — mirror how `technician_id` is tenant-guarded in this request; if technician_id uses a closure, copy the pattern):

```php
'appointment_id' => [
    'nullable', 'integer',
    Rule::exists('appointments', 'id')->where(function ($q) {
        $tenantId = $this->user()?->tenantId();
        if ($tenantId !== null) {
            $q->where('tenant_id', $tenantId);
        }
    }),
],
```

Ensure `use Illuminate\Validation\Rule;` is present.

- [ ] **Step 4: Read appointment_id in create() and persist in store()**

In `ServiceVisitController::create()`, add to the Inertia props:

```php
'presetAppointmentId' => request('appointment')
    ? (int) (Appointment::visibleTo(request()->user())->whereKey(request('appointment'))->value('id'))
    : null,
```

(Add `use App\Models\Appointment;` if missing. `value('id')` returns null when not visible → prop becomes null.)

In `ServiceVisitController::store()`, include `appointment_id` when building the visit. Find where the `ServiceVisit::create([...])` / `forceFill` happens and add:

```php
'appointment_id' => $request->input('appointment_id'),
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=AppointmentPaymentCompletionTest`
Expected: both Task-3 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/ServiceVisitController.php app/Http/Requests/StoreServiceVisitRequest.php tests/Feature/AppointmentPaymentCompletionTest.php
git commit -m "feat(appointments): thread + validate appointment_id through record creation"
```

---

## Task 4: Auto-complete appointment on payment

**Files:**
- Modify: `app/Services/Payments/PaymentService.php`
- Modify: `app/Actions/Payments/HandleGatewayCallback.php`
- Test: `tests/Feature/AppointmentPaymentCompletionTest.php` (extend)

- [ ] **Step 1: Write the failing tests**

Add to `AppointmentPaymentCompletionTest`:

```php
public function test_cash_payment_completes_linked_appointment(): void
{
    [$boss, $client] = $this->bossWithClient();
    $appt = $this->makeAppointmentFor($client, $boss);     // status pending
    $txn  = $this->pendingCashTransactionForVisitWith($client, $boss, ['appointment_id' => $appt->id]);

    $this->actingAs($boss)->post(route('payments.cash', $txn))->assertRedirect();

    $this->assertSame('completed', $appt->fresh()->status);
}

public function test_cancelled_appointment_stays_cancelled_after_payment(): void
{
    [$boss, $client] = $this->bossWithClient();
    $appt = $this->makeAppointmentFor($client, $boss, ['status' => 'cancelled']);
    $txn  = $this->pendingCashTransactionForVisitWith($client, $boss, ['appointment_id' => $appt->id]);

    $this->actingAs($boss)->post(route('payments.cash', $txn))->assertRedirect();

    $this->assertSame('cancelled', $appt->fresh()->status);
}

public function test_payment_without_linked_appointment_is_noop(): void
{
    [$boss, $client] = $this->bossWithClient();
    $txn = $this->pendingCashTransactionForVisitWith($client, $boss, ['appointment_id' => null]);

    $this->actingAs($boss)->post(route('payments.cash', $txn))->assertRedirect();
    // no exception, transaction paid
    $this->assertSame('paid', $txn->fresh()->status);
}
```

Add helper `pendingCashTransactionForVisitWith()` — creates a `ServiceVisit` (with the given `appointment_id`) + a pending `Transaction` (`status` pending, `visit_id` set). Verify the `payments.cash` route name + method (`PaymentController::cash`) and that the test user has `collect_payment` permission (grant it in the helper, mirroring how other payment tests set it up).

- [ ] **Step 2: Run to verify failure**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=AppointmentPaymentCompletionTest`
Expected: the two completion tests FAIL (appointment stays pending); the no-op test may already pass.

- [ ] **Step 3: Add completeLinkedAppointment to PaymentService**

In `app/Services/Payments/PaymentService.php`, add the method:

```php
public function completeLinkedAppointment(Transaction $transaction): void
{
    $visit = $transaction->visit()->first();
    if (! $visit || ! $visit->appointment_id) {
        return;
    }

    $appointment = $visit->appointment()->first();
    if (! $appointment || $appointment->status === 'cancelled') {
        return;
    }

    // Reached via the visit's own FK; assert same tenant before mutating.
    if ($appointment->tenant_id !== $visit->tenant_id) {
        return;
    }

    $appointment->update(['status' => 'completed']);
}
```

Add `use App\Models\Appointment;` only if referenced; here it is reached via relation so no import needed.

Wire it into `confirmCash()` — inside the existing `DB::transaction`, right after `$this->issueReceipt($transaction);`:

```php
            $this->issueReceipt($transaction);
            $this->completeLinkedAppointment($transaction);
```

- [ ] **Step 4: Wire the webhook path**

In `app/Actions/Payments/HandleGatewayCallback.php`, in the `PaymentStatus::PAID` branch, after `$this->payments->issueReceipt($transaction);` add:

```php
                $this->payments->issueReceipt($transaction);
                $this->payments->completeLinkedAppointment($transaction);
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=AppointmentPaymentCompletionTest`
Expected: all Task-3 + Task-4 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Payments/PaymentService.php app/Actions/Payments/HandleGatewayCallback.php tests/Feature/AppointmentPaymentCompletionTest.php
git commit -m "feat(appointments): auto-complete linked appointment on payment success (CHG-004)"
```

---

## Task 5: Appointment controller — stat card + status edit validation

**Files:**
- Modify: `app/Http/Controllers/AppointmentController.php`
- Modify: `app/Http/Requests/UpdateAppointmentRequest.php`
- Test: `tests/Feature/AppointmentTest.php`

- [ ] **Step 1: Write the failing test**

Add to `AppointmentTest`:

```php
public function test_admin_can_set_status_directly_via_update(): void
{
    [$boss, $client] = $this->bossWithClient();             // reuse/replicate helper
    $appt = $this->makeAppointmentFor($client, $boss, ['status' => 'pending']);

    $this->actingAs($boss)->put(route('appointments.update', $appt), array_merge(
        $this->validAppointmentPayload($appt),
        ['status' => 'completed'],
    ))->assertRedirect();

    $this->assertSame('completed', $appt->fresh()->status);
}
```

`validAppointmentPayload()` returns the fields `UpdateAppointmentRequest` requires (date, time, phone, address, technician_id, etc.) for the given appointment.

- [ ] **Step 2: Run to verify failure**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=AppointmentTest`
Expected: FAIL — `status` not accepted/applied on update.

- [ ] **Step 3: Accept status in UpdateAppointmentRequest**

In `app/Http/Requests/UpdateAppointmentRequest.php`, add to `rules()`:

```php
'status' => ['sometimes', \Illuminate\Validation\Rule::in(\App\Models\Appointment::STATUSES)],
```

And in the data-mapping method (`appointmentData()` if present, or wherever `update()` reads validated input), include `status` when present so `AppointmentController::update()` persists it. If `update()` uses `$request->appointmentData()`, add `'status' => $this->input('status')` guarded by `$this->filled('status')`.

- [ ] **Step 4: Replace the "Confirmed" stat with "Completed"**

In `AppointmentController::index()`, change the stat computation:

```php
'month_completed' => $appointments->where('status', 'completed')->count(),
```

(Replace `month_confirmed`.) Keep `month_pending`. Note: the frontend (Task 6) reads `stats.month_completed`.

- [ ] **Step 5: Run the test to verify it passes**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=AppointmentTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/AppointmentController.php app/Http/Requests/UpdateAppointmentRequest.php tests/Feature/AppointmentTest.php
git commit -m "feat(appointments): completed stat + admin status override on edit"
```

---

## Task 6: Appointments table — actions, serial column, stat label

**Files:**
- Modify: `resources/js/Pages/Appointments/Index.vue`

- [ ] **Step 1: Add the serial column definition**

In the `columns` array, insert after the `client` entry:

```js
    { key: 'serial',       label: 'Serial' },
```

- [ ] **Step 2: Render the serial cell + clean the client cell**

Add a cell template (desktop):

```html
                <!-- Serial -->
                <template #cell-serial="{ row }">
                    <Link v-if="row.client" :href="route('clients.show', row.client.id)" class="font-mono text-xs text-primary hover:underline">#{{ row.client.serial_no }}</Link>
                    <span v-else class="text-xs text-ink-soft">Non client</span>
                </template>
```

In `#cell-client`, remove the serial sub-line:

```html
                <template #cell-client="{ row }">
                    <div class="font-medium text-ink">{{ row.client?.name ?? row.customer_name ?? 'Walk-in' }}</div>
                </template>
```

In the mobile `#card`, replace the name+serial block with name + serial/Non-client:

```html
                            <div>
                                <div class="font-medium text-ink">{{ row.client?.name ?? row.customer_name ?? 'Walk-in' }}</div>
                                <Link v-if="row.client" :href="route('clients.show', row.client.id)" class="font-mono text-xs text-primary hover:underline">#{{ row.client.serial_no }}</Link>
                                <span v-else class="font-mono text-xs text-ink-soft">Non client</span>
                            </div>
```

Ensure `Link` is imported: add `import { Link } from '@inertiajs/vue3';` to the script (it is currently used but the import line only pulls `Head, router` — verify and add `Link`).

- [ ] **Step 3: Rename action + collapse status buttons (desktop + mobile)**

In `#cell-actions`, replace the action row with:

```html
                <template #cell-actions="{ row }">
                    <div class="flex items-center justify-end gap-2 whitespace-nowrap text-xs font-medium">
                        <Link
                            v-if="row.client"
                            :href="route('service-records.create', { client: row.client.id, technician_id: row.technician_id, appointment: row.id })"
                            class="text-ok hover:text-ok/80"
                        >Add Record</Link>
                        <button class="text-primary hover:text-primary-hover" @click="openEdit(row)">Edit</button>
                        <button
                            v-if="(transitions[row.status] ?? []).includes('cancelled')"
                            class="text-danger hover:underline"
                            @click="setStatus(row, 'cancelled')"
                        >Cancel Appointment</button>
                    </div>
                </template>
```

Apply the same change to the mobile `#card` action row (use `+ Record` → `Add Record`, append `appointment: row.id` to the link params, same single Cancel button).

- [ ] **Step 4: Update setStatus label map + stat card**

In `setStatus`, the label map can be reduced to `{ cancelled: 'cancel' }` (only path used now):

```js
const setStatus = async (a, status) => {
    const label = { cancelled: 'cancel', completed: 'complete' }[status] ?? status;
```

Change the "Confirmed" StatCard to "Completed":

```html
            <StatCard label="Completed" :value="stats.month_completed ?? 0" variant="ok"
                :sub="'done this month'">
```

(Keep its icon.)

- [ ] **Step 5: Build + eyeball**

Run: `docker compose exec -T laravel.test npm run build`
Expected: build succeeds. Manually verify the table shows Add Record / Edit / Cancel Appointment and a Serial column. (No automated frontend test in this suite.)

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Appointments/Index.vue
git commit -m "feat(appointments): Add Record/Edit/Cancel actions, serial column, completed stat (CHG-002/FEAT-002)"
```

---

## Task 7: AppointmentModal — autofill, technician default, status select, cancel redirect

**Files:**
- Modify: `resources/js/Pages/Appointments/Partials/AppointmentModal.vue`
- Modify: `resources/js/Pages/Appointments/Index.vue` (close handler)

- [ ] **Step 1: Make the rehydrate watcher immediate (autofill fix — BUG-003/004)**

In `AppointmentModal.vue`, change the open watcher:

```js
watch(() => props.open, (open) => {
    // ... unchanged body ...
}, { immediate: true });
```

The body already early-returns on `!open`, so the closed initial state is safe; when `Index.vue` opens the modal during setup, `immediate` runs the handler and applies `presetClient`.

- [ ] **Step 2: Add status field to the form + edit rehydrate**

In `useForm({...})` add `status: 'pending',`. In the edit branch of the watcher add:

```js
        form.status = a.status ?? 'pending';
```

In the else (new) branch, after `form.reset()`, default the technician (Step 3) — status stays the reset default `'pending'`.

- [ ] **Step 3: Technician default = self, drop Unassigned (CHG-003)**

Import the page to read the current user. At top of script add:

```js
import { usePage } from '@inertiajs/vue3';
const page = usePage();
```

In the new-appointment else branch of the watcher, after `form.reset()`:

```js
        form.status = 'pending';
        if (props.technicians) form.technician_id = page.props.auth?.user?.id ?? null;
        if (props.presetClient) applyClient(props.presetClient);
```

In the technician `<select>`, remove the Unassigned option:

```html
                        <select v-model="form.technician_id" class="...">
                            <option v-for="t in technicians" :key="t.id" :value="t.id">{{ t.name }}</option>
                        </select>
```

- [ ] **Step 4: Add the Status select (edit only)**

After the Technician block, add:

```html
                    <!-- Status (edit only) -->
                    <div v-if="isEdit">
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Status</label>
                        <select v-model="form.status" class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary">
                            <option value="pending">Pending</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                        <p v-if="form.errors.status" class="mt-1 text-sm text-danger">{{ form.errors.status }}</p>
                    </div>
```

`submit()` already sends the whole form via `form.put(...)`, so `status` is included on update; on create the store ignores it (always pending).

- [ ] **Step 5: Cancel/close returns to client (BUG-003)**

In `AppointmentModal.vue`, the Cancel button and backdrop currently `emit('close')`. Keep that — handle redirect in the parent so the modal stays generic.

In `Index.vue`, replace the modal's `@close="modalOpen = false"` with a handler:

```html
        <AppointmentModal
            :open="modalOpen"
            :appointment="editing"
            :preset-client="presetClient"
            :technicians="technicians"
            @close="onModalClose"
        />
```

Add the handler in script:

```js
const onModalClose = () => {
    modalOpen.value = false;
    // Opened from a client profile (or reminder) → return there on cancel.
    if (props.presetClient && !editing.value) {
        router.visit(route('clients.show', props.presetClient.id));
    }
};
```

Note: successful submit calls `emit('close')` via `onSuccess` AFTER the server redirect is already queued by Inertia; to avoid a double-navigate, the server `store()` redirect to `appointments.index` wins. If a race is observed in manual testing, guard `onModalClose` with a `submitted` ref set in the modal's `onSuccess`. Verify behaviour in Step 6; only add the guard if needed.

- [ ] **Step 6: Build + eyeball**

Run: `docker compose exec -T laravel.test npm run build`
Manually verify: open New appointment from a client → fields autofilled on FIRST open; Cancel returns to the client profile; technician pre-selected to own name with no Unassigned option; editing an appointment shows a Status dropdown.

- [ ] **Step 7: Commit**

```bash
git add resources/js/Pages/Appointments/Partials/AppointmentModal.vue resources/js/Pages/Appointments/Index.vue
git commit -m "feat(appointments): autofill on first open, self technician default, status select, cancel→client (BUG-003/004, CHG-003)"
```

---

## Task 8: Service record Create — accept appointment_id

**Files:**
- Modify: `resources/js/Pages/ServiceRecords/Create.vue`

- [ ] **Step 1: Add the prop + form field**

In `defineProps`, add:

```js
    presetAppointmentId: { type: Number, default: null },
```

In the `useForm({...})`, add:

```js
    appointment_id: props.presetAppointmentId ?? null,
```

No UI — it travels silently with the submit. (`ServiceVisitController::store` from Task 3 persists it.)

- [ ] **Step 2: Build**

Run: `docker compose exec -T laravel.test npm run build`
Expected: build succeeds.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/ServiceRecords/Create.vue
git commit -m "feat(appointments): carry appointment_id into service-record create (FEAT-001/CHG-004)"
```

---

## Task 9: Suite sweep + full verification

**Files:**
- Modify: any test/fixture referencing old statuses.

- [ ] **Step 1: Find stale status references**

Run: `docker exec saifzz-aircond-laravel.test-1 grep -rn "'confirmed'\|'done'" tests/ database/seeders/ app/`
Expected: review each hit. Update test fixtures/assertions to `pending`/`completed`. Leave unrelated `done` strings (e.g. unrelated domains) alone. Check `AppointmentSeeder`/`DatabaseSeeder` if they set appointment statuses.

- [ ] **Step 2: Run the full suite**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test`
Expected: GREEN. Fix any remaining failures from the status enum change (MultiTenantIsolationTest, ServiceVisitTest, etc.).

- [ ] **Step 3: Final build**

Run: `docker compose exec -T laravel.test npm run build`
Expected: clean build.

- [ ] **Step 4: Commit any fixups**

```bash
git add -A
git commit -m "test(appointments): sweep fixtures for new status enum"
```

---

## Self-review notes (for the executor)

- Spec coverage: BUG-003/004 (Task 7 immediate watch + cancel redirect), CHG-002 (Task 6 actions), CHG-003 (Task 7 technician), CHG-004 (Tasks 1–5 status + link + payment), FEAT-001 (Task 8 + existing preset), FEAT-002 (Task 6 serial column). All covered.
- The frontend `Link` import in `Index.vue` MUST be verified (Task 6 Step 2) — current file uses `<Link>` but imports only `Head, router`. If it currently works via a global registration, adding the explicit import is harmless; if not, this also fixes a latent bug.
- `month_completed` is the agreed stat key across Task 5 (controller) and Task 6 (Vue).
- `completeLinkedAppointment` is the agreed method name across Task 4 cash + webhook paths.

## Deployment (on merge to main)

- `php artisan migrate` (2 migrations: status collapse + appointment_id).
- No reseed required.
- `npm run build`.
