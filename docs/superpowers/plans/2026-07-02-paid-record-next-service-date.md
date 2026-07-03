# Set Next Service Date On A Paid Service Line — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let Khalid set/correct `service_lines.next_service_date` on a line even after its transaction is `paid`, without reopening any other field of the record.

**Architecture:** One new route + controller method on `ServiceVisitController`, scoped to a single line, exempt from the existing `pending`-only lock. On save it resyncs `client_units.next_service_date`/`next_service_type` using the same logic already in `store()`/`update()`. Frontend adds an inline edit control to `ServiceRecords/Show.vue` for lines whose service type requires next-service.

**Tech Stack:** Laravel (Blade-less, Inertia + Vue 3 SPA), PHPUnit feature tests, no JS test runner in this repo (`package.json` has no test script) — frontend step is verified manually in-browser.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-07-02-paid-record-next-service-date-design.md`.
- No other field on a paid record becomes editable — only `next_service_date` on `service_lines`.
- No status check on the new endpoint — it must work for `pending`, `paid`, and `void` alike.
- Always editable, not null-only (Khalid may need to correct a wrong date too).
- Reuse the exact resync snippet pattern from `ServiceVisitController.php:158-169`/`271-280` (unit's `next_service_type` = the line's own `service_type` string).
- Follow existing code style in `ServiceVisitController.php`: fully-qualified `\App\Models\ClientUnit::` calls inline (no new `use` import), inline `$request->validate()` for simple actions (matches `destroy()`'s reason validation), `abort_unless` for guards.

---

### Task 1: Backend endpoint — set next service date on a paid line

**Files:**
- Modify: `routes/web.php` (add route near existing `service-records.*` group, `routes/web.php:63-69`)
- Modify: `app/Http/Controllers/ServiceVisitController.php` (add `use App\Models\ServiceLine;` import at top with the other model imports, add new method)
- Test: `tests/Feature/ServiceVisitNextServiceDateTest.php` (new file)

**Interfaces:**
- Produces: route `service-records.lines.next-service-date` (PATCH), accepts `next_service_date` (nullable date string) in the request body, resolves `{serviceRecord}` → `ServiceVisit`, `{line}` → `ServiceLine` via implicit route-model binding.
- Consumes: `ServiceVisit::scopeVisibleTo()` (`app/Models/ServiceVisit.php:93-106`) for the visibility guard — same pattern as `show()`/`edit()`.

- [ ] **Step 1: Write the failing feature tests**

Create `tests/Feature/ServiceVisitNextServiceDateTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientUnit;
use App\Models\User;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceVisitNextServiceDateTest extends TestCase
{
    use RefreshDatabase;

    private function boss(): User
    {
        $boss = User::factory()->admin()->create();
        $boss->update(['tenant_id' => $boss->id]);

        return $boss->fresh();
    }

    /** A paid visit (Cash) with one Cleaning line, optionally attached to a unit. */
    private function paidVisitWithLine(User $boss, ?ClientUnit $unit = null): \App\Models\ServiceVisit
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
        ]);
        $visit->lines()->create([
            'service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted',
            'unit_id' => $unit?->id,
            'units' => 1, 'rate' => 60, 'discount' => 0,
        ]);
        $txn = $visit->transaction()->create([
            'txn_id' => 'TXN-20260701-' . str_pad((string) $visit->id, 3, '0', STR_PAD_LEFT),
            'amount' => 60, 'method' => 'Cash', 'status' => 'pending',
        ]);

        app(PaymentService::class)->confirmCash($txn);

        return $visit->fresh(['transaction', 'lines']);
    }

    public function test_can_set_next_service_date_on_a_paid_line(): void
    {
        $boss = $this->boss();
        $visit = $this->paidVisitWithLine($boss);
        $line = $visit->lines->first();

        $this->actingAs($boss)
            ->patch(route('service-records.lines.next-service-date', ['serviceRecord' => $visit, 'line' => $line]), [
                'next_service_date' => '2026-10-01',
            ])
            ->assertRedirect(route('service-records.show', $visit));

        $this->assertSame('2026-10-01', $line->fresh()->next_service_date->format('Y-m-d'));
    }

    public function test_can_overwrite_an_already_set_date_on_a_paid_line(): void
    {
        $boss = $this->boss();
        $visit = $this->paidVisitWithLine($boss);
        $line = $visit->lines->first();
        $line->update(['next_service_date' => '2026-08-01']);

        $this->actingAs($boss)
            ->patch(route('service-records.lines.next-service-date', ['serviceRecord' => $visit, 'line' => $line]), [
                'next_service_date' => '2026-11-15',
            ]);

        $this->assertSame('2026-11-15', $line->fresh()->next_service_date->format('Y-m-d'));
    }

    public function test_resyncs_client_unit_when_line_has_a_unit(): void
    {
        $boss = $this->boss();
        $client = Client::create(['name' => 'Ali', 'phone' => '013-0000000', 'address' => 'KL', 'tenant_id' => $boss->tenantId()]);
        $unit = ClientUnit::create(['client_id' => $client->id, 'label' => 'Living Room', 'unit_type' => 'Wall Mounted']);
        $visit = $client->visits()->create([
            'visit_date' => '2026-07-01', 'warranty_months' => 0, 'total_amount' => 60,
            'created_by' => $boss->id, 'tenant_id' => $boss->tenantId(),
        ]);
        $line = $visit->lines()->create([
            'service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'unit_id' => $unit->id,
            'units' => 1, 'rate' => 60, 'discount' => 0,
        ]);
        $txn = $visit->transaction()->create([
            'txn_id' => 'TXN-20260701-901', 'amount' => 60, 'method' => 'Cash', 'status' => 'pending',
        ]);
        app(PaymentService::class)->confirmCash($txn);

        $this->actingAs($boss)
            ->patch(route('service-records.lines.next-service-date', ['serviceRecord' => $visit, 'line' => $line]), [
                'next_service_date' => '2026-12-25',
            ]);

        $fresh = $unit->fresh();
        $this->assertSame('2026-12-25', $fresh->next_service_date->format('Y-m-d'));
        $this->assertSame('Cleaning', $fresh->next_service_type);
    }

    public function test_clearing_to_null_updates_line_but_does_not_blank_unit(): void
    {
        $boss = $this->boss();
        $client = Client::create(['name' => 'Ali', 'phone' => '013-0000000', 'address' => 'KL', 'tenant_id' => $boss->tenantId()]);
        $unit = ClientUnit::create([
            'client_id' => $client->id, 'label' => 'Living Room', 'unit_type' => 'Wall Mounted',
            'next_service_date' => '2026-09-01', 'next_service_type' => 'Cleaning',
        ]);
        $visit = $client->visits()->create([
            'visit_date' => '2026-07-01', 'warranty_months' => 0, 'total_amount' => 60,
            'created_by' => $boss->id, 'tenant_id' => $boss->tenantId(),
        ]);
        $line = $visit->lines()->create([
            'service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'unit_id' => $unit->id,
            'next_service_date' => '2026-09-01',
            'units' => 1, 'rate' => 60, 'discount' => 0,
        ]);
        $txn = $visit->transaction()->create([
            'txn_id' => 'TXN-20260701-902', 'amount' => 60, 'method' => 'Cash', 'status' => 'pending',
        ]);
        app(PaymentService::class)->confirmCash($txn);

        $this->actingAs($boss)
            ->patch(route('service-records.lines.next-service-date', ['serviceRecord' => $visit, 'line' => $line]), [
                'next_service_date' => null,
            ]);

        $this->assertNull($line->fresh()->next_service_date);
        $this->assertSame('2026-09-01', $unit->fresh()->next_service_date->format('Y-m-d'));
    }

    public function test_404_when_line_does_not_belong_to_service_record(): void
    {
        $boss = $this->boss();
        $visitA = $this->paidVisitWithLine($boss);
        $visitB = $this->paidVisitWithLine($boss);
        $lineFromB = $visitB->lines->first();

        $this->actingAs($boss)
            ->patch(route('service-records.lines.next-service-date', ['serviceRecord' => $visitA, 'line' => $lineFromB]), [
                'next_service_date' => '2026-10-01',
            ])
            ->assertStatus(404);
    }

    public function test_403_when_record_not_visible_to_scoped_technician(): void
    {
        $boss = $this->boss();
        $visit = $this->paidVisitWithLine($boss);
        $line = $visit->lines->first();

        $otherTech = User::factory()->create(['tenant_id' => $boss->tenantId()]);

        $this->actingAs($otherTech)
            ->patch(route('service-records.lines.next-service-date', ['serviceRecord' => $visit, 'line' => $line]), [
                'next_service_date' => '2026-10-01',
            ])
            ->assertStatus(403);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=ServiceVisitNextServiceDateTest`
Expected: FAIL — route `service-records.lines.next-service-date` not defined (`RouteNotFoundException`).

- [ ] **Step 3: Add the route**

In `routes/web.php`, immediately after line 69 (`Route::delete('service-records/{serviceRecord}', ...)`):

```php
        Route::patch('service-records/{serviceRecord}/lines/{line}/next-service-date', [ServiceVisitController::class, 'updateNextServiceDate'])->name('service-records.lines.next-service-date');
```

- [ ] **Step 4: Add the controller method**

In `app/Http/Controllers/ServiceVisitController.php`, add the import alongside the existing model imports (after line 10, `use App\Models\ServiceVisit;`):

```php
use App\Models\ServiceLine;
```

Add the method immediately after `destroy()` (after line 316, before the closing `/** ... */` docblock of `normalizeLine`):

```php
    public function updateNextServiceDate(Request $request, ServiceVisit $serviceRecord, ServiceLine $line): RedirectResponse
    {
        abort_unless(
            ServiceVisit::whereKey($serviceRecord->getKey())->visibleTo($request->user())->exists(),
            403,
        );
        abort_unless($line->visit_id === $serviceRecord->id, 404);

        $data = $request->validate([
            'next_service_date' => ['nullable', 'date'],
        ]);

        DB::transaction(function () use ($data, $line, $serviceRecord) {
            $line->update(['next_service_date' => $data['next_service_date']]);

            if ($line->unit_id && !empty($data['next_service_date'])) {
                \App\Models\ClientUnit::where('id', $line->unit_id)
                    ->where('client_id', $serviceRecord->client_id)
                    ->update([
                        'next_service_date' => $data['next_service_date'],
                        'next_service_type' => $line->service_type,
                    ]);
            }
        });

        return redirect()->route('service-records.show', $serviceRecord)
            ->with('success', 'Next service date updated.');
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=ServiceVisitNextServiceDateTest`
Expected: PASS, all 6 tests green.

- [ ] **Step 6: Run the full ServiceVisit test suite to check for regressions**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=ServiceVisit`
Expected: PASS, no regressions in `ServiceVisitTest`, `ServiceVisitUpdateTest`, `ServiceVisitVoidTest`.

- [ ] **Step 7: Commit**

```bash
git add routes/web.php app/Http/Controllers/ServiceVisitController.php tests/Feature/ServiceVisitNextServiceDateTest.php
git commit -m "feat: allow setting next service date on a paid service line"
```

---

### Task 2: Frontend — inline edit control on the paid record page

**Files:**
- Modify: `app/Http/Controllers/ServiceVisitController.php` (`show()` method, `ServiceVisitController.php:189-207`)
- Modify: `resources/js/Pages/ServiceRecords/Show.vue`

**Interfaces:**
- Consumes: route `service-records.lines.next-service-date` from Task 1.
- Consumes: `ServiceType.requires_next_service` (`app/Models/ServiceType.php`) to decide which lines get the edit control.

- [ ] **Step 1: Pass which service types require next-service to the Show page**

In `app/Http/Controllers/ServiceVisitController.php`, modify `show()` (`ServiceVisitController.php:203-206`) to add a new prop:

```php
        return Inertia::render('ServiceRecords/Show', [
            'visit' => $serviceRecord,
            'googleReview' => ['qrUrl' => $qrUrl, 'url' => $biz['google_review_url']],
            'requiresNextServiceTypes' => ServiceType::where('requires_next_service', true)->pluck('name'),
        ]);
```

(`ServiceType` is already imported at `ServiceVisitController.php:9`.)

- [ ] **Step 2: Add editing state and handlers to Show.vue**

In `resources/js/Pages/ServiceRecords/Show.vue`, in the `<script setup>` block, add `requiresNextServiceTypes` to `defineProps` (after line 14):

```js
    requiresNextServiceTypes: { type: Array, default: () => [] },
```

Add after the `lineLabel` const (after line 45):

```js
const requiresNext = (l) => props.requiresNextServiceTypes.includes(l.service_type);

const editingLineId = ref(null);
const editDate = ref('');

const startEditNextService = (l) => {
    editingLineId.value = l.id;
    editDate.value = l.next_service_date ? l.next_service_date.slice(0, 10) : '';
};

const cancelEditNextService = () => {
    editingLineId.value = null;
};

const saveNextServiceDate = (l) => {
    router.patch(
        route('service-records.lines.next-service-date', { serviceRecord: props.visit.id, line: l.id }),
        { next_service_date: editDate.value || null },
        { preserveScroll: true, onSuccess: () => { editingLineId.value = null; } },
    );
};
```

- [ ] **Step 3: Replace the read-only next-service line in the template**

In the same file, replace line 124:

```vue
                                    <p v-if="l.next_service_date" class="mt-1 text-xs font-medium text-primary">Next service: {{ fmtDate(l.next_service_date) }}</p>
```

with:

```vue
                                    <div v-if="requiresNext(l) && l.unit_id" class="mt-1.5 flex items-center gap-2">
                                        <template v-if="editingLineId === l.id">
                                            <input type="date" v-model="editDate" class="rounded-ra border border-line px-2 py-1 text-xs" />
                                            <button type="button" class="text-xs font-semibold text-primary" @click="saveNextServiceDate(l)">Save</button>
                                            <button type="button" class="text-xs text-ink-soft" @click="cancelEditNextService">Cancel</button>
                                        </template>
                                        <template v-else>
                                            <span class="text-xs font-medium text-primary">Next service: {{ l.next_service_date ? fmtDate(l.next_service_date) : 'Not set' }}</span>
                                            <button type="button" class="text-xs text-primary underline" @click="startEditNextService(l)">Edit</button>
                                        </template>
                                    </div>
                                    <p v-else-if="l.next_service_date" class="mt-1 text-xs font-medium text-primary">Next service: {{ fmtDate(l.next_service_date) }}</p>
```

- [ ] **Step 4: Manually verify in the browser**

Run: `npm run dev` (Vite HMR) alongside the existing `docker compose` stack.

1. Open a **paid** service record (`/service-records/{id}`) that has a Cleaning/Installation/Troubleshoot line with `next_service_date` null and a `unit_id` set.
2. Confirm "Next service: Not set" + "Edit" link renders.
3. Click Edit, pick a date, click Save — page updates without full reload, date now shows.
4. Reload the page, confirm the date persisted.
5. Open the client's profile page and confirm the unit's "Next service" now reflects the same date.
6. Click Edit again, change the date, Save — confirm it overwrites (not blocked).
7. Open a **pending** record with the same line shape — confirm the same edit control still works there too (endpoint isn't pending-gated).
8. Open a line with a service type that doesn't require next-service (e.g. Repair) — confirm no edit control appears and layout is unaffected.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ServiceVisitController.php resources/js/Pages/ServiceRecords/Show.vue
git commit -m "feat: inline edit for next service date on paid service records"
```
