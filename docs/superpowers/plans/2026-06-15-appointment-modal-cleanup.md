# Appointment Modal / Data Cleanup (CHG-007) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the vestigial `service_type`, `units`, `amount` fields from appointments (form already dropped them in CHG-009/010) and clean the data layer + remaining empty UI.

**Architecture:** Drop the 3 columns via migration; strip them from the model, the form request, and the controller prop; remove the dead Amount column and always-empty service_type badges from the appointment + dashboard views. Fix the test suite that still asserts those fields.

**Tech Stack:** Laravel 12 (PHP 8.5), Inertia + Vue 3, Pest/PHPUnit tests via `docker exec saifzz-aircond-laravel.test-1 php artisan test`, Vite build via `docker compose exec -T laravel.test npm run build`.

**Test runner note:** PHP/artisan only run inside the Docker container. Always prefix: `docker exec saifzz-aircond-laravel.test-1 php artisan ...`. The agent's own shell has no PHP.

---

### Task 1: Drop columns migration

**Files:**
- Create: `database/migrations/2026_06_15_000001_drop_service_type_units_amount_from_appointments.php`

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
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['service_type', 'units', 'amount']);
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('service_type')->nullable();
            $table->integer('units')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
        });
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan migrate`
Expected: migration runs, "DONE". (Test DB migrates fresh per run via RefreshDatabase, so this is mainly a syntax check.)

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_06_15_000001_drop_service_type_units_amount_from_appointments.php
git commit -m "feat: CHG-007 drop service_type/units/amount columns from appointments"
```

---

### Task 2: Remove fields from Appointment model

**Files:**
- Modify: `app/Models/Appointment.php:26-49`

- [ ] **Step 1: Remove from `$fillable`**

Delete the `'service_type',`, `'units',`, and `'amount',` lines from the `$fillable` array. Resulting array:

```php
    protected $fillable = [
        'client_id',
        'technician_id',
        'datetime',
        'address',
        'phone',
        'status',
        'contacted_flag',
        'notes',
        'tenant_id',
    ];
```

- [ ] **Step 2: Remove from `casts()`**

Delete the `'units' => 'integer',` and `'amount' => 'decimal:2',` lines. Resulting method:

```php
    protected function casts(): array
    {
        return [
            'datetime' => 'datetime',
            'contacted_flag' => 'boolean',
        ];
    }
```

- [ ] **Step 3: Commit**

```bash
git add app/Models/Appointment.php
git commit -m "feat: CHG-007 drop appointment field casts/fillable"
```

---

### Task 3: Remove fields from the form request

**Files:**
- Modify: `app/Http/Requests/StoreAppointmentRequest.php:18-60`

(`UpdateAppointmentRequest` extends `StoreAppointmentRequest`, so this covers both.)

- [ ] **Step 1: Remove the 3 validation rules**

Delete these lines from `rules()`:

```php
            'service_type' => ['nullable', 'string', Rule::exists('service_types', 'name')],
            'units' => ['nullable', 'integer', 'min:1'],
            'amount' => ['nullable', 'numeric', 'min:0'],
```

The `use Illuminate\Validation\Rule;` import is now unused — remove it too.

- [ ] **Step 2: Remove the 3 keys from `appointmentData()`**

Delete these lines:

```php
            'service_type' => $this->input('service_type'),
            'units' => $this->input('units'),
            'amount' => $this->input('amount'),
```

Resulting `appointmentData()`:

```php
    public function appointmentData(): array
    {
        return [
            'client_id' => $this->input('client_id'),
            'datetime' => $this->datetime(),
            'phone' => $this->input('phone'),
            'address' => $this->input('address'),
            'notes' => $this->input('notes'),
        ];
    }
```

- [ ] **Step 3: Commit**

```bash
git add app/Http/Requests/StoreAppointmentRequest.php
git commit -m "feat: CHG-007 drop service_type/units/amount from appointment request"
```

---

### Task 4: Fix backend tests

**Files:**
- Modify: `tests/Feature/AppointmentTest.php`
- Verify/Modify: `tests/Feature/MultiTenantIsolationTest.php`, `tests/Feature/TechnicianScopingTest.php`, `tests/Feature/DashboardTest.php`

- [ ] **Step 1: Update `AppointmentTest::payload()` (lines 30-42)**

Remove `service_type`, `units`, `amount` from the default payload:

```php
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'date' => '2026-06-16',
            'time' => '10:00',
            'phone' => '011-22334455',
            'address' => 'Unit 3A, Sri Maju Condo',
            'notes' => null,
        ], $overrides);
    }
```

- [ ] **Step 2: Fix `test_store_creates_pending_appointment_with_combined_datetime` (lines 76-77)**

Delete these two assertion lines:

```php
        $this->assertSame('Installation', $a->service_type);
        $this->assertSame(3, $a->units);
```

- [ ] **Step 3: Fix `test_store_validates_required_fields` (lines 90-97)**

`service_type` is no longer validated. Replace the body so it posts an empty array and drops `service_type` from the expected error bag:

```php
    public function test_store_validates_required_fields(): void
    {
        $this->actingAs($this->setter())
            ->post(route('appointments.store'), [])
            ->assertSessionHasErrors(['date', 'time', 'phone', 'address']);
    }
```

- [ ] **Step 4: Fix `test_update_edits_an_appointment` (lines 106-127)**

The `Appointment::create([... 'service_type' => 'Cleaning' ...])` key is now ignored harmlessly — remove it for clarity. Remove the `service_type` override in the `payload()` call and the `service_type` assertion. Resulting test:

```php
    public function test_update_edits_an_appointment(): void
    {
        $a = Appointment::create([
            'datetime' => '2026-06-16 10:00',
            'phone' => '012-3456789',
            'address' => 'Old address',
            'status' => 'pending',
        ]);

        // Admin sees all data — appointment has no technician_id so a scoped tech cannot reach it.
        $this->actingAs(User::factory()->admin()->create())
            ->put(route('appointments.update', $a), $this->payload([
                'address' => 'New address',
            ]))
            ->assertRedirect();

        $a->refresh();
        $this->assertSame('New address', $a->address);
    }
```

- [ ] **Step 5: Strip `service_type`/`units`/`amount` keys from remaining `Appointment::create([...])` calls in AppointmentTest**

These keys are silently ignored now (not fillable), but remove them for clarity. They appear at approx lines 131-191 (status-transition tests, index/search tests). Use this grep to find every appointment-context occurrence:

Run: `grep -n "service_type\|'units'\|'amount'" tests/Feature/AppointmentTest.php`

For each line that is part of an `Appointment::create([...])` array, remove the `'service_type' => '...',` (and any `'units'`/`'amount'`) entry. Do NOT touch lines that assert on service_line/transaction data (there are none in this file — all hits are appointment creates).

- [ ] **Step 6: Scan the other three test files**

Run: `grep -n "service_type\|units\|amount" tests/Feature/MultiTenantIsolationTest.php tests/Feature/TechnicianScopingTest.php tests/Feature/DashboardTest.php`

For each hit, determine context:
- If it is inside an `Appointment::create([...])` array OR an assertion on an `Appointment` instance's `service_type`/`units`/`amount` → remove it.
- If it relates to `ServiceLine`, `ServiceVisit`, `Transaction`, or `ServiceFee` (the price/visit domain) → LEAVE IT. Those columns still exist.

Distinguish by the model/table named in the surrounding `create()`/factory call.

- [ ] **Step 7: Run the full suite**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test`
Expected: all green (~258). If any failure references appointment `service_type`/`units`/`amount`, fix per Step 6's rule.

- [ ] **Step 8: Commit**

```bash
git add tests/
git commit -m "test: CHG-007 drop appointment service_type/units/amount from tests"
```

---

### Task 5: Drop serviceTypes prop from the controller

**Files:**
- Modify: `app/Http/Controllers/AppointmentController.php:80`

- [ ] **Step 1: Remove the `serviceTypes` line from `index()`'s Inertia payload**

Delete this line (the modal has no service-type select):

```php
            'serviceTypes' => ServiceType::orderBy('name')->pluck('name')->all(),
```

If `use App\Models\ServiceType;` is now unused elsewhere in the file, remove that import. (Verify: `grep -n "ServiceType" app/Http/Controllers/AppointmentController.php` — if no other hits, remove the import.)

- [ ] **Step 2: Sanity check the page still loads**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=AppointmentTest`
Expected: green.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/AppointmentController.php
git commit -m "feat: CHG-007 stop passing serviceTypes to appointments index"
```

---

### Task 6: Clean Appointments/Index.vue

**Files:**
- Modify: `resources/js/Pages/Appointments/Index.vue`

- [ ] **Step 1: Remove the Amount column definition (line ~90)**

In the `columns` array delete:

```js
    { key: 'amount',       label: 'Amount',  align: 'right' },
```

- [ ] **Step 2: Remove the `#cell-amount` template (lines ~255-258)**

Delete the entire block:

```html
                <!-- Amount -->
                <template #cell-amount="{ value }">
                    <span class="font-mono font-semibold text-navy-800">{{ money(value) }}</span>
                </template>
```

- [ ] **Step 3: Remove the empty service_type Badge in the day panel (line ~182)**

Change:

```html
                        <span class="font-medium text-ink">{{ a.client?.name ?? 'Walk-in' }}</span>
                        <Badge :variant="serviceVariant(a.service_type)">{{ a.service_type }}</Badge>
                        <Badge class="ml-auto" :variant="statusVariant(a.status.charAt(0).toUpperCase() + a.status.slice(1))">{{ a.status }}</Badge>
```

to (drop the middle Badge):

```html
                        <span class="font-medium text-ink">{{ a.client?.name ?? 'Walk-in' }}</span>
                        <Badge class="ml-auto" :variant="statusVariant(a.status.charAt(0).toUpperCase() + a.status.slice(1))">{{ a.status }}</Badge>
```

- [ ] **Step 4: Remove the empty service_type in the Today sidebar (line ~202)**

Change:

```html
                        <span class="min-w-0 flex-1 truncate text-ink">{{ a.client?.name ?? 'Walk-in' }} — {{ a.service_type }}</span>
```

to:

```html
                        <span class="min-w-0 flex-1 truncate text-ink">{{ a.client?.name ?? 'Walk-in' }}</span>
```

- [ ] **Step 5: Remove now-unused imports/props/helpers**

- `money` helper (line ~59): grep the file for other `money(` uses (`grep -n "money(" resources/js/Pages/Appointments/Index.vue`). The Amount column was the only consumer — if no other hits, delete the `const money = ...` line.
- `serviceVariant` import (line 11): it was only used by the removed day-panel Badge. Grep `grep -n "serviceVariant" resources/js/Pages/Appointments/Index.vue` — if no hits remain, remove it from the import on line 11 (keep `statusVariant`).
- `serviceTypes` prop (line 27): remove `serviceTypes: { type: Array, default: () => [] },` from `defineProps`.
- Modal binding (line ~326): remove `:service-types="serviceTypes"` from `<AppointmentModal ... />`.

- [ ] **Step 6: Build to verify no compile errors**

Run: `docker compose exec -T laravel.test npm run build`
Expected: build succeeds, no "serviceVariant is not defined" / unused-ref errors.

- [ ] **Step 7: Commit**

```bash
git add resources/js/Pages/Appointments/Index.vue
git commit -m "feat: CHG-007 remove amount column + empty service_type badges from appointments index"
```

---

### Task 7: Drop serviceTypes prop from AppointmentModal.vue

**Files:**
- Modify: `resources/js/Pages/Appointments/Partials/AppointmentModal.vue:8`

- [ ] **Step 1: Remove the unused prop**

Delete this line from `defineProps`:

```js
    serviceTypes: { type: Array, default: () => [] },
```

Confirm it is referenced nowhere else in the file: `grep -n "serviceTypes" resources/js/Pages/Appointments/Partials/AppointmentModal.vue` → expect no remaining hits.

- [ ] **Step 2: Commit**

```bash
git add resources/js/Pages/Appointments/Partials/AppointmentModal.vue
git commit -m "feat: CHG-007 drop unused serviceTypes prop from appointment modal"
```

---

### Task 8: Clean Dashboard.vue upcoming-appointment row

**Files:**
- Modify: `resources/js/Pages/Dashboard.vue:216`

- [ ] **Step 1: Remove the empty appointment service_type**

Change line 216:

```html
                        <span class="text-ink">{{ a.client?.name ?? 'Walk-in' }} — {{ a.service_type }}</span>
```

to:

```html
                        <span class="text-ink">{{ a.client?.name ?? 'Walk-in' }}</span>
```

Do NOT touch the other `service_type`/`amount` references in this file (lines ~99, 100, 283, 291, 295, 317, 321, 385) — those are transaction columns and remain valid.

- [ ] **Step 2: Commit**

```bash
git add resources/js/Pages/Dashboard.vue
git commit -m "feat: CHG-007 remove empty appointment service_type from dashboard"
```

---

### Task 9: Final verification

- [ ] **Step 1: Full test suite**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test`
Expected: all green (~258 tests).

- [ ] **Step 2: Production build**

Run: `docker compose exec -T laravel.test npm run build`
Expected: build succeeds clean.

- [ ] **Step 3: Final grep sweep — confirm no dangling appointment field reads**

Run: `grep -rn "a\.service_type\|appointment.*->amount\|appointment.*->units" resources/js app`
Expected: no appointment-context hits (transaction/service_line hits in Dashboard/ReportService are fine and expected).

---

## Deploy note (post-merge)

After this merges to `main` and deploys, run on production:
`docker compose exec -T laravel.test php artisan migrate`
to drop the 3 columns in the live DB.
