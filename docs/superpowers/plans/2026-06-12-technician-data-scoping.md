# Technician Data Scoping Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Scope a technician's visible data (jobs, revenue, reports, appointments) to the work they performed, while admins and techs granted `view_all_data` keep business-wide visibility.

**Architecture:** Add an explicit `technician_id` owner to `service_visits` and `appointments`. Scoping is a query-layer filter (`scopeVisibleTo`) orthogonal to the existing capability Gates. A new non-default permission `view_all_data` widens a technician back to global. Revenue/report aggregates funnel through `ServiceVisit`, so one filter scopes them all.

**Tech Stack:** Laravel 11, PHPUnit (`extends TestCase`, `RefreshDatabase`), PostgreSQL (`ilike`), Inertia + React (TypeScript). Tests run with `php artisan test`.

---

## File Structure

- `database/migrations/2026_06_12_000110_add_technician_scoping.php` — **create**: `technician_id` FK on `service_visits` + `appointments`, backfill visits from `created_by`.
- `app/Models/User.php` — **modify**: add `view_all_data` to `PERMISSIONS`, add `seesAllData()`.
- `app/Models/ServiceVisit.php` — **modify**: `technician_id` fillable, `technician()` relation, `scopeVisibleTo`.
- `app/Models/Appointment.php` — **modify**: `technician_id` fillable, `technician()` relation, `scopeVisibleTo`.
- `app/Http/Controllers/ServiceVisitController.php` — **modify**: scope index, 403 show guard, store sets `technician_id`, create() passes technician list.
- `app/Http/Requests/StoreServiceVisitRequest.php` — **modify**: validate optional `technician_id`.
- `app/Http/Controllers/AppointmentController.php` — **modify**: scope index, store/update set `technician_id`.
- `app/Services/Reports/ReportService.php` — **modify**: optional `?int $technicianId` on `kpis`/`servicesByType`/`transactions`.
- `app/Http/Controllers/DashboardController.php` — **modify**: pass scoping id, filter appointments, omit reminders KPI when scoped.
- `app/Http/Controllers/ReportController.php` — **modify**: scope export.
- `app/Http/Controllers/PaymentController.php` — **modify**: 403 unless txn's visit visible.
- `app/Http/Controllers/DocumentController.php` — **modify**: 403 unless txn's visit visible.
- `resources/js/Pages/ServiceRecords/Create.tsx` — **modify**: technician selector (admin only).
- `resources/js/Pages/Appointments/*` — **modify**: technician selector in the appointment form.
- Tests: new `tests/Feature/TechnicianScopingTest.php` plus additions to existing report/controller tests.

---

## Task 1: Migration — add `technician_id` and backfill

**Files:**
- Create: `database/migrations/2026_06_12_000110_add_technician_scoping.php`
- Test: `tests/Feature/TechnicianScopingTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TechnicianScopingTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ServiceVisit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TechnicianScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_visits_have_technician_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('service_visits', 'technician_id'));
    }

    public function test_appointments_have_technician_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('appointments', 'technician_id'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TechnicianScopingTest`
Expected: FAIL — `Failed asserting that false is true` (column missing).

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_06_12_000110_add_technician_scoping.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_visits', function (Blueprint $table) {
            $table->foreignId('technician_id')->nullable()->after('created_by')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('technician_id')->nullable()->after('client_id')
                ->constrained('users')->nullOnDelete();
        });

        // Backfill: best available owner signal for existing visits is who recorded them.
        DB::statement('update service_visits set technician_id = created_by where technician_id is null');
    }

    public function down(): void
    {
        Schema::table('service_visits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('technician_id');
        });
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('technician_id');
        });
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=TechnicianScopingTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_06_12_000110_add_technician_scoping.php tests/Feature/TechnicianScopingTest.php
git commit -m "feat(scoping): add technician_id to visits and appointments"
```

---

## Task 2: `view_all_data` permission + `seesAllData()` helper

**Files:**
- Modify: `app/Models/User.php`
- Test: `tests/Feature/TechnicianScopingTest.php`

- [ ] **Step 1: Write the failing test**

Append to `TechnicianScopingTest`:

```php
public function test_admin_sees_all_data(): void
{
    $this->assertTrue(User::factory()->admin()->create()->seesAllData());
}

public function test_default_technician_is_scoped(): void
{
    $tech = User::factory()->technician()->create(); // no permissions override → DEFAULT set
    $this->assertFalse($tech->seesAllData());
}

public function test_technician_with_view_all_data_sees_all(): void
{
    $tech = User::factory()->technician()->create([
        'permissions' => ['view_clients', 'view_all_data'],
    ]);
    $this->assertTrue($tech->seesAllData());
}

public function test_view_all_data_is_in_catalogue_but_not_default(): void
{
    $this->assertContains('view_all_data', User::PERMISSIONS);
    $this->assertNotContains('view_all_data', User::DEFAULT_TECHNICIAN_PERMISSIONS);
    $this->assertNotContains('view_all_data', User::ADMIN_ONLY_PERMISSIONS);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TechnicianScopingTest`
Expected: FAIL — `Call to undefined method App\Models\User::seesAllData()`.

- [ ] **Step 3: Implement**

In `app/Models/User.php`, add `'view_all_data'` to the `PERMISSIONS` array (after `'export_data'`):

```php
    public const PERMISSIONS = [
        'view_clients',
        'record_service',
        'set_appointment',
        'collect_payment',
        'edit_client',
        'view_reports',
        'edit_fees',
        'export_data',
        'view_all_data',
        'manage_users',
    ];
```

Add the helper method after `hasPermission()`:

```php
    /**
     * True when the user sees every row (no per-technician scoping).
     * Admins short-circuit to true via hasPermission().
     */
    public function seesAllData(): bool
    {
        return $this->hasPermission('view_all_data');
    }
```

(No Gate change needed — `AppServiceProvider::registerPermissionGates()` defines a gate per `PERMISSIONS` entry automatically.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=TechnicianScopingTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/User.php tests/Feature/TechnicianScopingTest.php
git commit -m "feat(scoping): add view_all_data permission and seesAllData helper"
```

---

## Task 3: `ServiceVisit` — relation + `scopeVisibleTo`

**Files:**
- Modify: `app/Models/ServiceVisit.php`
- Test: `tests/Feature/TechnicianScopingTest.php`

- [ ] **Step 1: Write the failing test**

Add a helper and tests to `TechnicianScopingTest`:

```php
private function visitFor(?int $technicianId): ServiceVisit
{
    $client = Client::create(['name' => 'C', 'phone' => '011-0000000', 'address' => 'KL']);
    return $client->visits()->create([
        'visit_date' => '2026-06-01',
        'warranty_months' => 0,
        'total_amount' => 100,
        'created_by' => null,
        'technician_id' => $technicianId,
    ]);
}

public function test_visible_to_scopes_technician_to_own_visits(): void
{
    $alice = User::factory()->technician()->create();
    $bob = User::factory()->technician()->create();
    $this->visitFor($alice->id);
    $this->visitFor($bob->id);

    $this->assertSame(1, ServiceVisit::visibleTo($alice)->count());
}

public function test_visible_to_returns_all_for_admin(): void
{
    $admin = User::factory()->admin()->create();
    $alice = User::factory()->technician()->create();
    $this->visitFor($alice->id);
    $this->visitFor(null);

    $this->assertSame(2, ServiceVisit::visibleTo($admin)->count());
}
```

Add `use App\Models\ServiceVisit;` is already imported at the top of the test file.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TechnicianScopingTest`
Expected: FAIL — `Call to undefined method ...::visibleTo()`.

- [ ] **Step 3: Implement**

In `app/Models/ServiceVisit.php`:

Add `'technician_id'` to `$fillable` (after `'created_by'`):

```php
    protected $fillable = [
        'client_id',
        'visit_date',
        'warranty_months',
        'warranty_end',
        'total_amount',
        'created_by',
        'technician_id',
    ];
```

Add the `User` import and `Builder` import at the top:

```php
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
```

Add the relation (after `creator()`):

```php
    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }
```

Add the scope (after `transaction()`):

```php
    /**
     * Restrict to rows the user may see. All-data users (admins + view_all_data) see everything;
     * scoped technicians see only visits assigned to them.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->seesAllData() ? $query : $query->where('technician_id', $user->id);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=TechnicianScopingTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/ServiceVisit.php tests/Feature/TechnicianScopingTest.php
git commit -m "feat(scoping): ServiceVisit technician relation and visibleTo scope"
```

---

## Task 4: `Appointment` — relation + `scopeVisibleTo`

**Files:**
- Modify: `app/Models/Appointment.php`
- Test: `tests/Feature/TechnicianScopingTest.php`

- [ ] **Step 1: Write the failing test**

Add to `TechnicianScopingTest` (add `use App\Models\Appointment;` to the imports):

```php
private function appointmentFor(?int $technicianId): Appointment
{
    $client = Client::create(['name' => 'C', 'phone' => '011-0000000', 'address' => 'KL']);
    return $client->appointments()->create([
        'datetime' => '2026-06-20 10:00:00',
        'service_type' => 'Cleaning',
        'units' => 1,
        'status' => 'pending',
        'technician_id' => $technicianId,
    ]);
}

public function test_appointment_visible_to_scopes_to_own(): void
{
    $alice = User::factory()->technician()->create();
    $this->appointmentFor($alice->id);
    $this->appointmentFor(null); // unassigned

    $this->assertSame(1, Appointment::visibleTo($alice)->count());
}

public function test_appointment_visible_to_returns_all_for_admin(): void
{
    $admin = User::factory()->admin()->create();
    $alice = User::factory()->technician()->create();
    $this->appointmentFor($alice->id);
    $this->appointmentFor(null);

    $this->assertSame(2, Appointment::visibleTo($admin)->count());
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TechnicianScopingTest`
Expected: FAIL — `Call to undefined method ...::visibleTo()`.

- [ ] **Step 3: Implement**

In `app/Models/Appointment.php`:

Add `'technician_id'` to `$fillable` (after `'client_id'`).

Add imports at top:

```php
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
```

(`Builder` is already imported.)

Add the relation (after `client()`):

```php
    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }
```

Add the scope (after `scopeForMonth()`):

```php
    /**
     * Restrict to appointments the user may see. Unassigned (null technician) are visible
     * only to all-data users; scoped technicians see only appointments assigned to them.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->seesAllData() ? $query : $query->where('technician_id', $user->id);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=TechnicianScopingTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/Appointment.php tests/Feature/TechnicianScopingTest.php
git commit -m "feat(scoping): Appointment technician relation and visibleTo scope"
```

---

## Task 5: ServiceVisit write path + index scope + show guard

**Files:**
- Modify: `app/Http/Controllers/ServiceVisitController.php`
- Modify: `app/Http/Requests/StoreServiceVisitRequest.php`
- Test: `tests/Feature/TechnicianScopingTest.php`

- [ ] **Step 1: Write the failing test**

Add to `TechnicianScopingTest` (reuse `seedFees`/`payload` style; define locally):

```php
private function tech(array $perms = ['view_clients', 'record_service']): User
{
    return User::factory()->technician()->create(['permissions' => $perms]);
}

public function test_store_forces_scoped_technician_to_self_ignoring_payload(): void
{
    \App\Models\ServiceFee::insert([
        ['service_type' => 'Cleaning', 'option' => 'Wall Mounted', 'rate' => 60, 'pricing_mode' => 'fixed_per_unit', 'created_at' => now(), 'updated_at' => now()],
    ]);
    $alice = $this->tech();
    $bob = $this->tech();
    $client = Client::create(['name' => 'X', 'phone' => '011-0000000', 'address' => 'KL']);

    $this->actingAs($alice)->post(route('service-records.store'), [
        'client_mode' => 'existing',
        'client_id' => $client->id,
        'visit_date' => '2026-06-11',
        'warranty_months' => 0,
        'payment_method' => 'Cash',
        'technician_id' => $bob->id, // forged — must be ignored
        'lines' => [['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 1]],
    ])->assertRedirect();

    $this->assertSame($alice->id, ServiceVisit::latest('id')->first()->technician_id);
}

public function test_admin_store_honors_chosen_technician(): void
{
    \App\Models\ServiceFee::insert([
        ['service_type' => 'Cleaning', 'option' => 'Wall Mounted', 'rate' => 60, 'pricing_mode' => 'fixed_per_unit', 'created_at' => now(), 'updated_at' => now()],
    ]);
    $admin = User::factory()->admin()->create();
    $bob = $this->tech();
    $client = Client::create(['name' => 'X', 'phone' => '011-0000000', 'address' => 'KL']);

    $this->actingAs($admin)->post(route('service-records.store'), [
        'client_mode' => 'existing',
        'client_id' => $client->id,
        'visit_date' => '2026-06-11',
        'warranty_months' => 0,
        'payment_method' => 'Cash',
        'technician_id' => $bob->id,
        'lines' => [['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 1]],
    ])->assertRedirect();

    $this->assertSame($bob->id, ServiceVisit::latest('id')->first()->technician_id);
}

public function test_show_forbidden_for_non_owner_technician(): void
{
    $alice = $this->tech();
    $bob = $this->tech();
    $client = Client::create(['name' => 'X', 'phone' => '011-0000000', 'address' => 'KL']);
    $visit = $client->visits()->create([
        'visit_date' => '2026-06-01', 'warranty_months' => 0, 'total_amount' => 100,
        'created_by' => $bob->id, 'technician_id' => $bob->id,
    ]);

    $this->actingAs($alice)->get(route('service-records.show', $visit))->assertForbidden();
    $this->actingAs($bob)->get(route('service-records.show', $visit))->assertOk();
}

public function test_index_lists_only_own_visits_for_scoped_tech(): void
{
    $alice = $this->tech();
    $bob = $this->tech();
    foreach ([$alice->id, $bob->id, $alice->id] as $tid) {
        $client = Client::create(['name' => 'C'.$tid, 'phone' => '011-0000000', 'address' => 'KL']);
        $client->visits()->create([
            'visit_date' => '2026-06-01', 'warranty_months' => 0, 'total_amount' => 100,
            'created_by' => $tid, 'technician_id' => $tid,
        ]);
    }

    $this->actingAs($alice)->get(route('service-records.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('visits.total', 2));
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TechnicianScopingTest`
Expected: FAIL — store sets no `technician_id` (null), show returns 200 for non-owner, index total wrong.

- [ ] **Step 3: Implement**

In `app/Http/Requests/StoreServiceVisitRequest.php`, add to the `rules()` array:

```php
            'technician_id' => ['nullable', 'integer', 'exists:users,id'],
```

In `app/Http/Controllers/ServiceVisitController.php`:

Scope `index()` — change the base query (line 25) to:

```php
        $query = ServiceVisit::query()
            ->visibleTo(request()->user())
            ->with([
                'client:id,serial_no,name',
                'transaction:id,visit_id,status,method,txn_id',
                'lines:id,visit_id,service_type',
            ]);
```

In `store()`, set the owner. Replace the `$client->visits()->create([...])` block (lines 79-83) with:

```php
            $user = $request->user();
            // Scoped techs always own their own jobs; all-data users may assign.
            $technicianId = $user->seesAllData()
                ? ($data['technician_id'] ?? $user->id)
                : $user->id;

            $visit = $client->visits()->create([
                'visit_date' => $data['visit_date'],
                'warranty_months' => $data['warranty_months'],
                'created_by' => $user->id,
                'technician_id' => $technicianId,
            ]);
```

In `show()`, add an authorization guard as the first line:

```php
    public function show(ServiceVisit $serviceRecord): Response
    {
        abort_unless(
            ServiceVisit::whereKey($serviceRecord->getKey())->visibleTo(request()->user())->exists(),
            403,
        );

        $serviceRecord->load(['client', 'lines', 'transaction', 'creator:id,name']);
        // ...unchanged
```

In `create()`, pass the technician list so the form can render the selector for all-data users. Add to the `Inertia::render('ServiceRecords/Create', [...])` array:

```php
            'technicians' => request()->user()->seesAllData()
                ? \App\Models\User::where('role', \App\Models\User::ROLE_TECHNICIAN)
                    ->where('active', true)->orderBy('name')->get(['id', 'name'])
                : null,
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=TechnicianScopingTest`
Expected: PASS.

- [ ] **Step 5: Run the full ServiceVisit suite (regression)**

Run: `php artisan test --filter=ServiceVisitTest`
Expected: PASS — existing tests use a scoped recorder; their own visits remain visible.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/ServiceVisitController.php app/Http/Requests/StoreServiceVisitRequest.php tests/Feature/TechnicianScopingTest.php
git commit -m "feat(scoping): scope service-record index/show/store by technician"
```

---

## Task 6: Appointment write path + index scope

**Files:**
- Modify: `app/Http/Controllers/AppointmentController.php`
- Test: `tests/Feature/TechnicianScopingTest.php`

- [ ] **Step 1: Read the controller first**

Run: open `app/Http/Controllers/AppointmentController.php` to confirm the `index()` query, `store()`, and the form-data passed to the Inertia view, plus the request/validation used. Match the existing validation style (FormRequest or inline `$request->validate`).

- [ ] **Step 2: Write the failing test**

Add to `TechnicianScopingTest` (set_appointment permission required):

```php
public function test_appointment_index_scoped_to_own(): void
{
    $alice = User::factory()->technician()->create(['permissions' => ['view_clients', 'set_appointment']]);
    $this->appointmentFor($alice->id);
    $this->appointmentFor(null);

    $this->actingAs($alice)->get(route('appointments.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('appointments.total', 1));
}

public function test_store_appointment_forces_scoped_tech_to_self(): void
{
    $alice = User::factory()->technician()->create(['permissions' => ['view_clients', 'set_appointment']]);
    $bob = User::factory()->technician()->create(['permissions' => ['set_appointment']]);
    $client = Client::create(['name' => 'X', 'phone' => '011-0000000', 'address' => 'KL']);

    $this->actingAs($alice)->post(route('appointments.store'), [
        'client_id' => $client->id,
        'datetime' => '2026-07-01 09:00:00',
        'service_type' => 'Cleaning',
        'units' => 1,
        'technician_id' => $bob->id, // forged
    ])->assertRedirect();

    $this->assertSame($alice->id, \App\Models\Appointment::latest('id')->first()->technician_id);
}
```

> NOTE TO IMPLEMENTER: the exact `appointments.total` assertion assumes the index paginates under an `appointments` prop. If `index()` returns a plain collection (e.g. `forMonth`), assert on the count of the returned prop instead — adapt the assertion to the actual prop shape you saw in Step 1. The store-forcing behavior is the load-bearing assertion.

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --filter=TechnicianScopingTest`
Expected: FAIL — appointments not scoped, `technician_id` null.

- [ ] **Step 4: Implement**

In `AppointmentController@index`, add `->visibleTo($request->user())` (or `request()->user()`) to the appointment query before pagination/get.

In `AppointmentController@store`, after validation, force the owner before creating:

```php
        $user = $request->user();
        $validated['technician_id'] = $user->seesAllData()
            ? ($validated['technician_id'] ?? null)
            : $user->id;
```

Add `'technician_id' => ['nullable', 'integer', 'exists:users,id']` to the store validation rules. Pass a `technicians` list to the Inertia view for the form (same pattern as Task 5 `create()`), gated on `seesAllData()`.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=TechnicianScopingTest`
Expected: PASS.

- [ ] **Step 6: Run appointment regression suite**

Run: `php artisan test --filter=AppointmentTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/AppointmentController.php tests/Feature/TechnicianScopingTest.php
git commit -m "feat(scoping): scope appointment index and store by technician"
```

---

## Task 7: `ReportService` — optional technician scoping

**Files:**
- Modify: `app/Services/Reports/ReportService.php`
- Test: `tests/Feature/ReportServiceTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/ReportServiceTest.php` (follow its existing seeding helpers; sketch):

```php
public function test_transactions_scoped_to_technician(): void
{
    $alice = \App\Models\User::factory()->technician()->create();
    $bob = \App\Models\User::factory()->technician()->create();
    $this->paidVisitFor($alice->id, 100); // helper: create client+visit(technician_id)+paid txn
    $this->paidVisitFor($bob->id, 200);

    $service = app(\App\Services\Reports\ReportService::class);
    $rows = $service->transactions('all', null, $alice->id);

    $this->assertCount(1, $rows);
    $this->assertSame(100.0, $rows[0]['amount']);
}

public function test_kpis_revenue_scoped_to_technician(): void
{
    $alice = \App\Models\User::factory()->technician()->create();
    $bob = \App\Models\User::factory()->technician()->create();
    $this->paidVisitFor($alice->id, 100);
    $this->paidVisitFor($bob->id, 200);

    $service = app(\App\Services\Reports\ReportService::class);
    $this->assertSame(100.0, $service->kpis($alice->id)['revenue_all_time']);
    $this->assertSame(300.0, $service->kpis(null)['revenue_all_time']);
}
```

Add a `paidVisitFor(int $techId, float $amount)` helper to the test: create a client, a `ServiceVisit` with `technician_id` and a paid `Transaction` (`status => 'paid'`, `paid_at => now()`), plus at least one `ServiceLine` for `servicesByType`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ReportServiceTest`
Expected: FAIL — `transactions()`/`kpis()` don't accept the technician argument / don't scope.

- [ ] **Step 3: Implement**

In `app/Services/Reports/ReportService.php`:

`transactions()` signature → add the param and the filter:

```php
    public function transactions(string $period, ?int $limit = 50, ?int $technicianId = null): array
    {
        [$from, $to] = $this->range($period);

        $q = DB::table('transactions as t')
            ->join('service_visits as sv', 'sv.id', '=', 't.visit_id')
            ->join('clients as c', 'c.id', '=', 'sv.client_id')
            ->select(/* unchanged */);

        if ($technicianId !== null) {
            $q->where('sv.technician_id', $technicianId);
        }
        // ...rest unchanged
```

`servicesByType()`:

```php
    public function servicesByType(string $period, ?int $technicianId = null): array
    {
        [$from, $to] = $this->range($period);

        $q = DB::table('service_lines as sl')
            ->join('service_visits as sv', 'sv.id', '=', 'sl.visit_id');

        if ($technicianId !== null) {
            $q->where('sv.technician_id', $technicianId);
        }
        // ...rest unchanged
```

`kpis()` — must join `service_visits` to scope revenue, and scope client counts. Replace the body:

```php
    public function kpis(?int $technicianId = null): array
    {
        $now = Carbon::now();
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();
        $lastStart = $now->copy()->subMonthNoOverflow()->startOfMonth();
        $lastEnd = $now->copy()->subMonthNoOverflow()->endOfMonth();

        $paidRevenue = function (Carbon $start, Carbon $end) use ($technicianId): float {
            $q = DB::table('transactions as t')
                ->join('service_visits as sv', 'sv.id', '=', 't.visit_id')
                ->where('t.status', 'paid')
                ->whereBetween('t.paid_at', [$start, $end]);
            if ($technicianId !== null) {
                $q->where('sv.technician_id', $technicianId);
            }
            return (float) $q->sum('t.amount');
        };

        $revenueMonth = $paidRevenue($monthStart, $monthEnd);
        $revenueLast = $paidRevenue($lastStart, $lastEnd);

        $allTimeQ = DB::table('transactions as t')
            ->join('service_visits as sv', 'sv.id', '=', 't.visit_id')
            ->where('t.status', 'paid');
        if ($technicianId !== null) {
            $allTimeQ->where('sv.technician_id', $technicianId);
        }
        $revenueAllTime = (float) $allTimeQ->sum('t.amount');

        if ($technicianId === null) {
            $totalClients = Client::count();
            $clientsThisMonth = Client::whereBetween('created_at', [$monthStart, $monthEnd])->count();
            $pendingReminders = $this->reminders->dueList()['stats'];
            $pending = $pendingReminders['overdue'] + $pendingReminders['due_this_month'];
        } else {
            $totalClients = (int) DB::table('service_visits')
                ->where('technician_id', $technicianId)->distinct()->count('client_id');
            $clientsThisMonth = (int) DB::table('service_visits')
                ->where('technician_id', $technicianId)
                ->whereBetween('visit_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->distinct()->count('client_id');
            $pending = null; // reminders are client-global; omitted for scoped techs (v1)
        }

        return [
            'total_clients' => $totalClients,
            'clients_this_month' => $clientsThisMonth,
            'revenue_month' => $revenueMonth,
            'revenue_mom_pct' => $revenueLast > 0 ? (int) round((($revenueMonth - $revenueLast) / $revenueLast) * 100) : null,
            'revenue_all_time' => $revenueAllTime,
            'pending_reminders' => $pending,
        ];
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ReportServiceTest`
Expected: PASS (new + existing — existing call `kpis()` with no arg → null → unchanged global behavior).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Reports/ReportService.php tests/Feature/ReportServiceTest.php
git commit -m "feat(scoping): optional technician scoping in ReportService"
```

---

## Task 8: Dashboard — pass scoping id, scope appointments, omit reminders KPI

**Files:**
- Modify: `app/Http/Controllers/DashboardController.php`
- Test: `tests/Feature/DashboardTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/DashboardTest.php` (a scoped tech with `view_reports`):

```php
public function test_dashboard_revenue_scoped_for_technician(): void
{
    $alice = \App\Models\User::factory()->technician()->create(['permissions' => ['view_clients', 'view_reports']]);
    $bob = \App\Models\User::factory()->technician()->create();
    // helper to create a paid visit per tech (reuse the suite's seeding):
    $this->paidVisitFor($alice->id, 100);
    $this->paidVisitFor($bob->id, 200);

    $this->actingAs($alice)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('report.kpis.revenue_all_time', 100.0)
            ->where('report.kpis.pending_reminders', null));
}
```

(If `DashboardTest` lacks a `paidVisitFor` helper, add one mirroring the ReportService test helper.)

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=DashboardTest`
Expected: FAIL — revenue shows 300 (global), reminders not null.

- [ ] **Step 3: Implement**

In `app/Http/Controllers/DashboardController.php@index`, after the `view_reports` gate passes, compute the scope id and thread it through:

```php
        $user = $request->user();
        $scopeId = $user->seesAllData() ? null : $user->id;

        return Inertia::render('Dashboard', [
            'canReport' => true,
            'period' => $period,
            'month' => $month,
            'report' => [
                'kpis' => $reports->kpis($scopeId),
                'servicesByType' => $reports->servicesByType($period, $scopeId),
                'transactions' => $reports->transactions($period, 50, $scopeId),
            ],
            'appointments' => Appointment::query()
                ->visibleTo($user)
                ->with('client:id,serial_no,name')
                ->forMonth($month)
                ->orderBy('datetime')
                ->get(),
        ]);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=DashboardTest`
Expected: PASS (new + existing — admins/all-data pass null → unchanged).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/DashboardController.php tests/Feature/DashboardTest.php
git commit -m "feat(scoping): scope dashboard reports and appointments by technician"
```

---

## Task 9: Report CSV export — scope rows

**Files:**
- Modify: `app/Http/Controllers/ReportController.php`
- Test: `tests/Feature/TechnicianScopingTest.php`

- [ ] **Step 1: Write the failing test**

Add to `TechnicianScopingTest` (export needs `export_data`; reuse `paidVisitFor`-style seeding inline):

```php
public function test_export_scoped_to_technician(): void
{
    $alice = User::factory()->technician()->create(['permissions' => ['export_data']]);
    $bob = User::factory()->technician()->create();

    foreach ([[$alice->id, 100.0], [$bob->id, 200.0]] as [$tid, $amt]) {
        $client = Client::create(['name' => 'C'.$tid, 'phone' => '011-0000000', 'address' => 'KL']);
        $visit = $client->visits()->create([
            'visit_date' => '2026-06-01', 'warranty_months' => 0, 'total_amount' => $amt,
            'created_by' => $tid, 'technician_id' => $tid,
        ]);
        $visit->transaction()->create([
            'txn_id' => 'TXN-20260601-'.str_pad((string) $visit->id, 3, '0', STR_PAD_LEFT),
            'amount' => $amt, 'method' => 'Cash', 'status' => 'paid', 'paid_at' => now(),
        ]);
    }

    $res = $this->actingAs($alice)->get(route('reports.transactions.export', ['period' => 'all']));
    $res->assertOk();
    $csv = $res->streamedContent();

    $this->assertStringContainsString('100.00', $csv);
    $this->assertStringNotContainsString('200.00', $csv);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TechnicianScopingTest`
Expected: FAIL — export contains both rows.

- [ ] **Step 3: Implement**

In `app/Http/Controllers/ReportController.php@exportTransactions`, scope the rows:

```php
        $user = $request->user();
        $scopeId = $user->seesAllData() ? null : $user->id;

        $rows = $reports->transactions($period, null, $scopeId);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=TechnicianScopingTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ReportController.php tests/Feature/TechnicianScopingTest.php
git commit -m "feat(scoping): scope transactions CSV export by technician"
```

---

## Task 10: Transaction-derived route guards (payments + documents)

**Files:**
- Modify: `app/Http/Controllers/PaymentController.php`
- Modify: `app/Http/Controllers/DocumentController.php`
- Test: `tests/Feature/TechnicianScopingTest.php`

- [ ] **Step 1: Read both controllers**

Open `PaymentController` (`show`, `cash`, `pay`) and `DocumentController` (`invoice`, `invoicePdf`, `receipt`, `receiptPdf`) to see how each resolves the `Transaction` and its `visit`. Confirm the route-model-bound variable name.

- [ ] **Step 2: Write the failing test**

Add to `TechnicianScopingTest`:

```php
private function paidTxnFor(int $techId): \App\Models\Transaction
{
    $client = Client::create(['name' => 'C'.$techId, 'phone' => '011-0000000', 'address' => 'KL']);
    $visit = $client->visits()->create([
        'visit_date' => '2026-06-01', 'warranty_months' => 0, 'total_amount' => 100,
        'created_by' => $techId, 'technician_id' => $techId,
    ]);
    return $visit->transaction()->create([
        'txn_id' => 'TXN-20260601-'.str_pad((string) $visit->id, 3, '0', STR_PAD_LEFT),
        'amount' => 100, 'method' => 'Cash', 'status' => 'pending',
    ]);
}

public function test_payment_show_forbidden_for_non_owner(): void
{
    $alice = User::factory()->technician()->create(['permissions' => ['collect_payment']]);
    $bob = User::factory()->technician()->create(['permissions' => ['collect_payment']]);
    $txn = $this->paidTxnFor($bob->id);

    $this->actingAs($alice)->get(route('payments.show', $txn))->assertForbidden();
    $this->actingAs($bob)->get(route('payments.show', $txn))->assertOk();
}

public function test_document_invoice_forbidden_for_non_owner(): void
{
    $alice = User::factory()->technician()->create(['permissions' => ['view_clients']]);
    $bob = User::factory()->technician()->create(['permissions' => ['view_clients']]);
    $txn = $this->paidTxnFor($bob->id);

    $this->actingAs($alice)->get(route('documents.invoice', $txn))->assertForbidden();
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --filter=TechnicianScopingTest`
Expected: FAIL — both return 200 for the non-owner.

- [ ] **Step 4: Implement**

Add a guard helper once, then call it at the top of each transaction-handling action. In **each** controller, add a private method (or inline `abort_unless`) that checks the transaction's visit is visible:

```php
    private function authorizeVisitScope(\App\Models\Transaction $transaction): void
    {
        abort_unless(
            \App\Models\ServiceVisit::whereKey($transaction->visit_id)
                ->visibleTo(request()->user())->exists(),
            403,
        );
    }
```

Call `$this->authorizeVisitScope($transaction);` as the first statement of:
- `PaymentController`: `show`, `cash`, `pay` (the `{transaction}` is the bound var; match its name). Leave `return` (gateway callback) alone — it is not RBAC-gated and runs post-redirect.
- `DocumentController`: `invoice`, `invoicePdf`, `receipt`, `receiptPdf`.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=TechnicianScopingTest`
Expected: PASS.

- [ ] **Step 6: Run payment + document regression suites**

Run: `php artisan test --filter=PaymentTest && php artisan test --filter=DocumentControllerTest`
Expected: PASS — existing tests act as admins or as the owning recorder.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/PaymentController.php app/Http/Controllers/DocumentController.php tests/Feature/TechnicianScopingTest.php
git commit -m "feat(scoping): guard payment and document routes by visit visibility"
```

---

## Task 11: Frontend — technician selector + scoped labels

**Files:**
- Modify: `resources/js/Pages/ServiceRecords/Create.tsx`
- Modify: `resources/js/Pages/ServiceRecords/Index.tsx`
- Modify: appointment form page under `resources/js/Pages/Appointments/`
- Test: manual (Inertia props verified in Tasks 5/6/8 already)

- [ ] **Step 1: Locate the pages**

Confirm exact paths: `resources/js/Pages/ServiceRecords/Create.tsx`, `Index.tsx`, and the appointments page(s). Read the props each receives (the controllers now pass `technicians` and scoped data).

- [ ] **Step 2: Create.tsx — technician selector**

When the `technicians` prop is non-null (all-data user), render a select bound to form field `technician_id` (options = `technicians`, default empty = "record as me"). When null (scoped tech), render nothing — the server forces self. Add `technician_id` to the Inertia `useForm` initial state as `''` and include it in the POST.

- [ ] **Step 3: Index.tsx — title**

Title the page "My Jobs" when the current user is scoped, "Service Records" otherwise. Source the flag from shared Inertia auth props (`HandleInertiaRequests` already shares the user; if it doesn't expose `seesAllData`, add a boolean there — check first). Keep it cosmetic; no behavior change.

- [ ] **Step 4: Appointment form — technician selector**

Same pattern as Step 2 on the appointment create/edit form: render the selector only when `technicians` prop is present; bind to `technician_id`.

- [ ] **Step 5: Build + typecheck**

Run: `npm run build`
Expected: builds with no TypeScript errors.

- [ ] **Step 6: Eyeball in dev (per user preference — use Vite HMR, not build)**

Run: `npm run dev` and verify: admin sees the technician dropdown on the record-service + appointment forms; a scoped technician does not, and their service-records index reads "My Jobs". (See memory: prefer `npm run dev` for visual checks.)

- [ ] **Step 7: Commit**

```bash
git add resources/js/Pages/ServiceRecords/Create.tsx resources/js/Pages/ServiceRecords/Index.tsx resources/js/Pages/Appointments
git commit -m "feat(scoping): technician selector and My Jobs labels"
```

---

## Task 12: Shared Inertia prop for `seesAllData` (if needed by Task 11)

**Files:**
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Test: `tests/Feature/InertiaSharedPropsTest.php`

> Only do this task if Step 3 of Task 11 found the shared auth user does not already expose a scoping flag.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/InertiaSharedPropsTest.php`:

```php
public function test_shares_sees_all_data_flag(): void
{
    $tech = \App\Models\User::factory()->technician()->create();
    $this->actingAs($tech)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('auth.user.sees_all_data', false));
}
```

(Adapt the prop path to the existing `auth.user` shape in this test file.)

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=InertiaSharedPropsTest`
Expected: FAIL — prop missing.

- [ ] **Step 3: Implement**

In `HandleInertiaRequests::share()`, add `sees_all_data` to the shared user payload:

```php
'sees_all_data' => $request->user()?->seesAllData() ?? false,
```

(Place it alongside the existing user fields — match how the file currently composes `auth.user`.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=InertiaSharedPropsTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Middleware/HandleInertiaRequests.php tests/Feature/InertiaSharedPropsTest.php
git commit -m "feat(scoping): share sees_all_data flag to the frontend"
```

---

## Final verification

- [ ] **Run the whole suite**

Run: `php artisan test`
Expected: all green. If any pre-existing test broke, it most likely assumed global visibility — fix by giving its actor `view_all_data`/admin or by assigning `technician_id` to the seeded visit, matching the new scoping contract.

- [ ] **Grant flow sanity (manual)**

In `npm run dev`: grant `view_all_data` to a scoped tech via the Users module; confirm their dashboard revenue and service-records list widen to business-wide.

---

## Notes for the implementer

- **Postgres:** this project uses `ilike` and Postgres; the `distinct()->count('client_id')` calls are Postgres-safe.
- **Gates unchanged:** `view_all_data` is enforced as data scope, not as a route gate. Do not add `can:view_all_data` middleware anywhere.
- **`created_by` stays:** it still records who entered the row (audit). `technician_id` is the new ownership/scoping signal. They diverge only when an admin records on a tech's behalf.
- **Commits:** do NOT add a Co-Authored-By trailer (user preference). Work directly on `main` (user preference).
