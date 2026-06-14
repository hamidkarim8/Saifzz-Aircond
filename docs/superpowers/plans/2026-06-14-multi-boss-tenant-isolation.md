# Multi-Boss Tenant Isolation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Isolate all data by boss (tenant) so Khalid and Saifzz each see only their own technicians, clients, service records, appointments, payments, reports and dropdowns.

**Architecture:** A single nullable `tenant_id` column on `users`, `clients`, `service_visits`, `appointments`. A boss's `tenant_id` equals their own user id (self-root); technicians inherit their boss's tenant. The existing `Model::visibleTo($user)` scope seam becomes tenant-aware, and a new `Client::scopeVisibleTo` is added. `tenant_id` is stamped explicitly in controllers on create. Closes CHG-002 and CHG-015.

**Tech Stack:** Laravel 12 (PHP 8.5), Postgres 16, Inertia/Vue. Tests run via `docker exec saifzz-aircond-laravel.test-1 php artisan test`.

**Spec:** `docs/superpowers/specs/2026-06-14-multi-boss-tenant-isolation-design.md`

---

## Critical seam contract (read before coding)

`where('tenant_id', null)` compiles to `tenant_id = NULL`, which matches **zero** rows. Existing tests create factory users and rows with no `tenant_id`. Therefore the tenant filter **must only be applied when the acting user's `tenantId()` is non-null**:

```php
if ($tid = $user->tenantId()) {
    $query->where('tenant_id', $tid);
}
```

Production guarantees every real user has a `tenant_id` (seeder sets bosses, controllers stamp technicians). Legacy/test fixtures with null tenant are intentionally not tenant-filtered — this keeps the existing 233-test suite green while production stays isolated. This contract is repeated in every scope and report method below.

## File structure

| File | Responsibility | Action |
|------|----------------|--------|
| `database/migrations/2026_06_14_000004_add_tenant_id_to_users_table.php` | tenant_id on users | Create |
| `database/migrations/2026_06_14_000005_add_tenant_id_to_clients_table.php` | tenant_id on clients | Create |
| `database/migrations/2026_06_14_000006_add_tenant_id_to_service_visits_table.php` | tenant_id on service_visits | Create |
| `database/migrations/2026_06_14_000007_add_tenant_id_to_appointments_table.php` | tenant_id on appointments | Create |
| `app/Models/User.php` | `tenantId()`, fillable | Modify |
| `app/Models/Client.php` | `scopeVisibleTo`, fillable | Modify |
| `app/Models/ServiceVisit.php` | tenant-aware `scopeVisibleTo`, fillable | Modify |
| `app/Models/Appointment.php` | tenant-aware `scopeVisibleTo`, fillable | Modify |
| `database/seeders/DatabaseSeeder.php` | self-root bosses | Modify |
| `app/Http/Controllers/ClientController.php` | stamp + scope index/lookup | Modify |
| `app/Http/Controllers/ServiceVisitController.php` | stamp + tenant client lookup + dropdowns | Modify |
| `app/Http/Controllers/AppointmentController.php` | stamp + dropdowns | Modify |
| `app/Http/Controllers/UserController.php` | tenant list/stamp/guards | Modify |
| `app/Http/Controllers/ClientUnitController.php` | tenant guard via parent client | Modify |
| `app/Services/Reports/ReportService.php` | tenant param on all aggregates | Modify |
| `app/Services/Reminders/ReminderService.php` | tenant param on dueList | Modify |
| `app/Http/Controllers/DashboardController.php` | pass tenantId | Modify |
| `app/Http/Controllers/ReportController.php` | pass tenantId | Modify |
| `tests/Feature/MultiTenantIsolationTest.php` | end-to-end isolation tests | Create |

---

## Task 1: Migrations — add `tenant_id` to four tables

**Files:**
- Create: `database/migrations/2026_06_14_000004_add_tenant_id_to_users_table.php`
- Create: `database/migrations/2026_06_14_000005_add_tenant_id_to_clients_table.php`
- Create: `database/migrations/2026_06_14_000006_add_tenant_id_to_service_visits_table.php`
- Create: `database/migrations/2026_06_14_000007_add_tenant_id_to_appointments_table.php`
- Test: `tests/Feature/MultiTenantIsolationTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MultiTenantIsolationTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MultiTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_id_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'tenant_id'));
        $this->assertTrue(Schema::hasColumn('clients', 'tenant_id'));
        $this->assertTrue(Schema::hasColumn('service_visits', 'tenant_id'));
        $this->assertTrue(Schema::hasColumn('appointments', 'tenant_id'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=test_tenant_id_columns_exist`
Expected: FAIL — `Failed asserting that false is true` (columns missing).

- [ ] **Step 3: Write the four migrations**

`2026_06_14_000004_add_tenant_id_to_users_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
```

`2026_06_14_000005_add_tenant_id_to_clients_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
```

`2026_06_14_000006_add_tenant_id_to_service_visits_table.php`:

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
            $table->foreignId('tenant_id')->nullable()->after('id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_visits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
```

`2026_06_14_000007_add_tenant_id_to_appointments_table.php`:

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
            $table->foreignId('tenant_id')->nullable()->after('id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=test_tenant_id_columns_exist`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_06_14_00000*_add_tenant_id_to_*.php tests/Feature/MultiTenantIsolationTest.php
git commit -m "feat: CHG-002 add tenant_id columns to users, clients, service_visits, appointments"
```

---

## Task 2: User model `tenantId()` + fillable + self-root seeder

**Files:**
- Modify: `app/Models/User.php:13` (Fillable attribute), add `tenantId()` method
- Modify: `database/seeders/DatabaseSeeder.php:32-50`
- Test: `tests/Feature/MultiTenantIsolationTest.php`

- [ ] **Step 1: Write the failing test**

Add to `MultiTenantIsolationTest`:

```php
public function test_seeded_bosses_are_their_own_tenant_root(): void
{
    $this->seed(\Database\Seeders\DatabaseSeeder::class);

    $khalid = \App\Models\User::where('email', 'khalid@admin.com')->first();
    $saifzz = \App\Models\User::where('email', 'saifzz@admin.com')->first();

    $this->assertSame($khalid->id, $khalid->tenantId());
    $this->assertSame($saifzz->id, $saifzz->tenantId());
    $this->assertNotSame($khalid->tenantId(), $saifzz->tenantId());
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=test_seeded_bosses_are_their_own_tenant_root`
Expected: FAIL — `Call to undefined method App\Models\User::tenantId()`.

- [ ] **Step 3: Implement `tenantId()` + fillable, then self-root in seeder**

In `app/Models/User.php`, change the Fillable attribute (line 13) to include `tenant_id`:

```php
#[Fillable(['name', 'email', 'password', 'role', 'permissions', 'active', 'tenant_id'])]
```

Add this method to `User` (place it after `seesAllData()`):

```php
/**
 * The tenant (boss) this user belongs to. Bosses are their own tenant root
 * (tenant_id === own id). Null only for legacy/test fixtures — production
 * users always have a tenant.
 */
public function tenantId(): ?int
{
    return $this->tenant_id;
}
```

In `database/seeders/DatabaseSeeder.php`, replace the two `firstOrCreate` blocks (lines 32-50) with versions that self-root after create:

```php
$khalid = User::firstOrCreate(
    ['email' => 'khalid@admin.com'],
    [
        'name' => 'Superadmin Khalid',
        'role' => User::ROLE_ADMIN,
        'active' => true,
        'password' => Hash::make('khalid123'),
    ],
);
if ($khalid->tenant_id === null) {
    $khalid->update(['tenant_id' => $khalid->id]);
}

$saifzz = User::firstOrCreate(
    ['email' => 'saifzz@admin.com'],
    [
        'name' => 'Superadmin Saifzz',
        'role' => User::ROLE_ADMIN,
        'active' => true,
        'password' => Hash::make('saifzz123'),
    ],
);
if ($saifzz->tenant_id === null) {
    $saifzz->update(['tenant_id' => $saifzz->id]);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=test_seeded_bosses_are_their_own_tenant_root`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/User.php database/seeders/DatabaseSeeder.php tests/Feature/MultiTenantIsolationTest.php
git commit -m "feat: CHG-002 User::tenantId() and self-root boss seeding"
```

---

## Task 3: Tenant-aware scope seam (ServiceVisit, Appointment) + new Client scope

**Files:**
- Modify: `app/Models/ServiceVisit.php:86-89` and `$fillable` (line 16-24)
- Modify: `app/Models/Appointment.php:82-85` and `$fillable` (line 26-38)
- Modify: `app/Models/Client.php` — add `Builder` import, `scopeVisibleTo`, `tenant_id` fillable
- Test: `tests/Feature/MultiTenantIsolationTest.php`

- [ ] **Step 1: Write the failing test**

Add a helper + tests to `MultiTenantIsolationTest`. Put the helper near the top of the class:

```php
/** Create a boss admin that is its own tenant root. */
private function boss(): \App\Models\User
{
    $boss = \App\Models\User::factory()->admin()->create();
    $boss->update(['tenant_id' => $boss->id]);

    return $boss->fresh();
}

private function clientFor(\App\Models\User $boss): \App\Models\Client
{
    return \App\Models\Client::create([
        'name' => 'C', 'phone' => '011-0000000', 'address' => 'KL',
        'tenant_id' => $boss->tenantId(),
    ]);
}

private function visitFor(\App\Models\Client $client, \App\Models\User $boss): \App\Models\ServiceVisit
{
    return $client->visits()->create([
        'visit_date' => '2026-06-01', 'warranty_months' => 0, 'total_amount' => 100,
        'created_by' => $boss->id, 'technician_id' => null,
        'tenant_id' => $boss->tenantId(),
    ]);
}

private function appointmentFor(\App\Models\Client $client, \App\Models\User $boss): \App\Models\Appointment
{
    return $client->appointments()->create([
        'datetime' => '2026-06-20 10:00:00', 'status' => 'pending',
        'technician_id' => null, 'tenant_id' => $boss->tenantId(),
    ]);
}
```

```php
public function test_visit_scope_isolates_tenants(): void
{
    $khalid = $this->boss();
    $saifzz = $this->boss();
    $this->visitFor($this->clientFor($khalid), $khalid);
    $this->visitFor($this->clientFor($saifzz), $saifzz);

    $this->assertSame(1, \App\Models\ServiceVisit::visibleTo($khalid)->count());
    $this->assertSame(1, \App\Models\ServiceVisit::visibleTo($saifzz)->count());
}

public function test_appointment_scope_isolates_tenants(): void
{
    $khalid = $this->boss();
    $saifzz = $this->boss();
    $this->appointmentFor($this->clientFor($khalid), $khalid);
    $this->appointmentFor($this->clientFor($saifzz), $saifzz);

    $this->assertSame(1, \App\Models\Appointment::visibleTo($khalid)->count());
    $this->assertSame(1, \App\Models\Appointment::visibleTo($saifzz)->count());
}

public function test_client_scope_isolates_tenants(): void
{
    $khalid = $this->boss();
    $saifzz = $this->boss();
    $this->clientFor($khalid);
    $this->clientFor($saifzz);

    $this->assertSame(1, \App\Models\Client::visibleTo($khalid)->count());
    $this->assertSame(1, \App\Models\Client::visibleTo($saifzz)->count());
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter="test_visit_scope_isolates_tenants|test_appointment_scope_isolates_tenants|test_client_scope_isolates_tenants"`
Expected: FAIL — `Call to undefined method ...Client::visibleTo()` and visit/appointment counts wrong (both return 2).

- [ ] **Step 3: Implement scopes + fillable**

In `app/Models/ServiceVisit.php`, add `'tenant_id'` to `$fillable` (after `'technician_id'`), and replace `scopeVisibleTo` (lines 86-89):

```php
public function scopeVisibleTo(Builder $query, User $user): Builder
{
    // Tenant filter applies only when the user has a tenant; legacy/test
    // fixtures with null tenant are not filtered (see plan seam contract).
    if ($tid = $user->tenantId()) {
        $query->where('tenant_id', $tid);
    }

    if (! $user->seesAllData()) {
        $query->where('technician_id', $user->id);
    }

    return $query;
}
```

In `app/Models/Appointment.php`, add `'tenant_id'` to `$fillable` (after `'technician_id'`), and replace `scopeVisibleTo` (lines 82-85):

```php
public function scopeVisibleTo(Builder $query, User $user): Builder
{
    if ($tid = $user->tenantId()) {
        $query->where('tenant_id', $tid);
    }

    if (! $user->seesAllData()) {
        $query->where('technician_id', $user->id);
    }

    return $query;
}
```

In `app/Models/Client.php`: add `use Illuminate\Database\Eloquent\Builder;` and `use App\Models\User;` imports, add `'tenant_id'` to `$fillable`, and add the scope:

```php
/**
 * Restrict to clients in the user's tenant. A granted technician sees all
 * tenant clients (visibility is permission-gated upstream, not per-technician).
 */
public function scopeVisibleTo(Builder $query, User $user): Builder
{
    if ($tid = $user->tenantId()) {
        $query->where('tenant_id', $tid);
    }

    return $query;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter="test_visit_scope_isolates_tenants|test_appointment_scope_isolates_tenants|test_client_scope_isolates_tenants"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/ServiceVisit.php app/Models/Appointment.php app/Models/Client.php tests/Feature/MultiTenantIsolationTest.php
git commit -m "feat: CHG-002 tenant-aware visibleTo scopes + Client scope"
```

---

## Task 4: Stamp `tenant_id` on create (clients, visits, appointments, users)

**Files:**
- Modify: `app/Http/Controllers/ClientController.php:156-163` (store)
- Modify: `app/Http/Controllers/ServiceVisitController.php:88-103` (store — both client paths + visit)
- Modify: `app/Http/Controllers/AppointmentController.php:93-107` (store)
- Modify: `app/Http/Controllers/UserController.php:24-45` (store)
- Test: `tests/Feature/MultiTenantIsolationTest.php`

- [ ] **Step 1: Write the failing test**

Add to `MultiTenantIsolationTest`:

```php
public function test_store_paths_stamp_creator_tenant(): void
{
    $this->seed(\Database\Seeders\ServiceTypeSeeder::class);
    \App\Models\ServiceFee::insert([
        ['service_type' => 'Cleaning', 'option' => 'Wall Mounted', 'rate' => 60, 'pricing_mode' => 'fixed_per_unit', 'created_at' => now(), 'updated_at' => now()],
    ]);
    $khalid = $this->boss();

    // Client store
    $this->actingAs($khalid)->post(route('clients.store'), [
        'name' => 'New', 'phone' => '012-3456789', 'address' => 'KL',
    ])->assertRedirect();
    $client = \App\Models\Client::where('name', 'New')->first();
    $this->assertSame($khalid->id, $client->tenant_id);

    // Service visit store
    $this->actingAs($khalid)->post(route('service-records.store'), [
        'client_mode' => 'existing', 'client_id' => $client->id,
        'visit_date' => '2026-06-11', 'warranty_months' => 0, 'payment_method' => 'Cash',
        'lines' => [['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 1]],
    ])->assertRedirect();
    $this->assertSame($khalid->id, \App\Models\ServiceVisit::latest('id')->first()->tenant_id);

    // Appointment store
    $this->actingAs($khalid)->post(route('appointments.store'), [
        'client_id' => $client->id, 'date' => '2026-07-01', 'time' => '09:00',
        'phone' => '012-3456789', 'address' => 'KL',
    ])->assertRedirect();
    $this->assertSame($khalid->id, \App\Models\Appointment::latest('id')->first()->tenant_id);
}

public function test_technician_store_inherits_boss_tenant(): void
{
    $khalid = $this->boss();
    $this->actingAs($khalid)->post(route('users.store'), [
        'name' => 'Tech A', 'email' => 'techa@example.com', 'password' => 'password123',
    ])->assertRedirect();

    $tech = \App\Models\User::where('email', 'techa@example.com')->first();
    $this->assertSame($khalid->id, $tech->tenant_id);
}

public function test_boss_cannot_attach_visit_to_other_tenant_client(): void
{
    $this->seed(\Database\Seeders\ServiceTypeSeeder::class);
    \App\Models\ServiceFee::insert([
        ['service_type' => 'Cleaning', 'option' => 'Wall Mounted', 'rate' => 60, 'pricing_mode' => 'fixed_per_unit', 'created_at' => now(), 'updated_at' => now()],
    ]);
    $khalid = $this->boss();
    $saifzz = $this->boss();
    $saifzzClient = $this->clientFor($saifzz);

    $this->actingAs($khalid)->post(route('service-records.store'), [
        'client_mode' => 'existing', 'client_id' => $saifzzClient->id,
        'visit_date' => '2026-06-11', 'warranty_months' => 0, 'payment_method' => 'Cash',
        'lines' => [['service_type' => 'Cleaning', 'unit_type' => 'Wall Mounted', 'units' => 1]],
    ])->assertNotFound();
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter="test_store_paths_stamp_creator_tenant|test_technician_store_inherits_boss_tenant|test_boss_cannot_attach_visit_to_other_tenant_client"`
Expected: FAIL — tenant_id null (assertions on tenant_id fail) and the cross-tenant attach returns 302 not 404.

- [ ] **Step 3: Stamp tenant_id in all four store paths**

`ClientController::store` (replace lines 156-163):

```php
public function store(StoreClientRequest $request): RedirectResponse
{
    $client = Client::create($request->validated() + [
        'tenant_id' => $request->user()->tenantId(),
    ]);

    return redirect()
        ->route('clients.show', $client)
        ->with('success', "Client created — serial {$client->serial_no}.");
}
```

`ServiceVisitController::store` — replace the client resolution + visit create (lines 88-103). The existing-client lookup now scopes to the tenant (returns 404 cross-tenant), the new-client path stamps tenant, and the visit carries tenant:

```php
$user = $request->user();

$client = $data['client_mode'] === 'existing'
    ? Client::visibleTo($user)->findOrFail($data['client_id'])
    : Client::create($data['new_client'] + ['tenant_id' => $user->tenantId()]);

// Scoped techs always own their own jobs; all-data users may assign.
$technicianId = $user->seesAllData()
    ? ($data['technician_id'] ?? $user->id)
    : $user->id;

$visit = $client->visits()->create([
    'visit_date' => $data['visit_date'],
    'warranty_months' => $data['warranty_months'],
    'created_by' => $user->id,
    'technician_id' => $technicianId,
    'tenant_id' => $user->tenantId(),
]);
```

(Note: the `$user = $request->user();` line already exists at line 92 — remove the now-duplicate so `$user` is assigned once, before the client resolution.)

`AppointmentController::store` (replace lines 99-102):

```php
Appointment::create($request->appointmentData() + [
    'status' => 'pending',
    'technician_id' => $technicianId,
    'tenant_id' => $user->tenantId(),
]);
```

`UserController::store` (replace the `User::create([...])` block at lines 26-31):

```php
$user = User::create([
    'name' => $request->name,
    'email' => $request->email,
    'password' => Hash::make($request->password),
    'role' => User::ROLE_TECHNICIAN,
    'tenant_id' => $request->user()->tenantId(),
]);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter="test_store_paths_stamp_creator_tenant|test_technician_store_inherits_boss_tenant|test_boss_cannot_attach_visit_to_other_tenant_client"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ClientController.php app/Http/Controllers/ServiceVisitController.php app/Http/Controllers/AppointmentController.php app/Http/Controllers/UserController.php tests/Feature/MultiTenantIsolationTest.php
git commit -m "feat: CHG-002 stamp tenant_id on client/visit/appointment/user create"
```

---

## Task 5: Tenant-scope the client list and lookup

**Files:**
- Modify: `app/Http/Controllers/ClientController.php:32` (index query), `:138` (lookup query)
- Test: `tests/Feature/MultiTenantIsolationTest.php`

- [ ] **Step 1: Write the failing test**

Add to `MultiTenantIsolationTest`:

```php
public function test_client_index_and_lookup_are_tenant_scoped(): void
{
    $khalid = $this->boss();
    $saifzz = $this->boss();
    $kClient = $this->clientFor($khalid);          // belongs to Khalid
    $sClient = $this->clientFor($saifzz);          // belongs to Saifzz

    // Index: Khalid sees only his client
    $this->actingAs($khalid)->get(route('clients.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('clients.total', 1));

    // Lookup JSON: Khalid never sees Saifzz's client
    $res = $this->actingAs($khalid)->getJson(route('clients.lookup'));
    $res->assertOk();
    $ids = collect($res->json())->pluck('id')->all();
    $this->assertContains($kClient->id, $ids);
    $this->assertNotContains($sClient->id, $ids);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=test_client_index_and_lookup_are_tenant_scoped`
Expected: FAIL — Khalid's index total is 2 and lookup contains Saifzz's client id.

- [ ] **Step 3: Add `visibleTo` to index and lookup**

In `ClientController::index`, change the base query (line 32) from `Client::query()` to:

```php
$clients = Client::query()
    ->visibleTo($request->index_user ?? $request->user())
```

Use the request user directly — replace line 32 `$clients = Client::query()` with:

```php
$clients = Client::query()
    ->visibleTo($request->user())
```

In `ClientController::lookup`, change the base query (line 138) from `Client::query()` to:

```php
$clients = Client::query()
    ->visibleTo($request->user())
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=test_client_index_and_lookup_are_tenant_scoped`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ClientController.php tests/Feature/MultiTenantIsolationTest.php
git commit -m "feat: CHG-002 tenant-scope client index + lookup"
```

---

## Task 6: User management tenant isolation (rule 1)

**Files:**
- Modify: `app/Http/Controllers/UserController.php:16-22` (index), `:47-61` (update), `:63-70` (toggleActive)
- Test: `tests/Feature/MultiTenantIsolationTest.php`

- [ ] **Step 1: Write the failing test**

Add to `MultiTenantIsolationTest`:

```php
private function techFor(\App\Models\User $boss, string $email): \App\Models\User
{
    return \App\Models\User::factory()->technician()->create([
        'email' => $email, 'tenant_id' => $boss->id,
    ]);
}

public function test_user_index_lists_only_own_tenant_technicians(): void
{
    $khalid = $this->boss();
    $saifzz = $this->boss();
    $kTech = $this->techFor($khalid, 'kt@example.com');
    $sTech = $this->techFor($saifzz, 'st@example.com');

    $this->actingAs($khalid)->get(route('users.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('users', fn ($users) => collect($users)->pluck('id')->contains($kTech->id)
                && ! collect($users)->pluck('id')->contains($sTech->id)));
}

public function test_boss_cannot_update_other_tenant_technician(): void
{
    $khalid = $this->boss();
    $saifzz = $this->boss();
    $sTech = $this->techFor($saifzz, 'st2@example.com');

    $this->actingAs($khalid)->put(route('users.update', $sTech), [
        'name' => 'Hacked', 'permissions' => [],
    ])->assertNotFound();
}

public function test_boss_cannot_deactivate_other_tenant_technician(): void
{
    $khalid = $this->boss();
    $saifzz = $this->boss();
    $sTech = $this->techFor($saifzz, 'st3@example.com');

    $this->actingAs($khalid)->patch(route('users.toggle', $sTech))
        ->assertNotFound();
}
```

> Note: confirm the toggle route name with `php artisan route:list --name=users`. If it differs from `users.toggle`, use the actual name in the test.

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter="test_user_index_lists_only_own_tenant_technicians|test_boss_cannot_update_other_tenant_technician|test_boss_cannot_deactivate_other_tenant_technician"`
Expected: FAIL — index lists both techs; cross-tenant update/toggle return 200/302 not 404.

- [ ] **Step 3: Scope the user list + guard mutations**

`UserController::index` (replace lines 16-22):

```php
public function index(): Response
{
    $tenantId = request()->user()->tenantId();

    return Inertia::render('Users/Index', [
        'users' => User::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('role', User::ROLE_TECHNICIAN)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'active', 'permissions']),
        'grantablePermissions' => array_values(array_diff(User::PERMISSIONS, User::ADMIN_ONLY_PERMISSIONS)),
    ]);
}
```

`UserController::update` — add a tenant guard at the top (after the existing `isAdmin` abort, replace lines 49-51 region so it reads):

```php
public function update(UpdateUserRequest $request, User $user): RedirectResponse
{
    if ($user->isAdmin()) {
        abort(403);
    }

    abort_if(
        $request->user()->tenantId() !== null && $user->tenant_id !== $request->user()->tenantId(),
        404,
    );

    $user->name = $request->name;
    // ... unchanged below
```

`UserController::toggleActive` — add the same tenant guard after the self-deactivation check (line 65):

```php
public function toggleActive(Request $request, User $user): RedirectResponse
{
    abort_if($user->is($request->user()), 422, 'Cannot deactivate your own account.');

    abort_if(
        $request->user()->tenantId() !== null && $user->tenant_id !== $request->user()->tenantId(),
        404,
    );

    $user->update(['active' => ! $user->active]);

    return back()->with('success', $user->active ? 'Account activated.' : 'Account deactivated.');
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter="test_user_index_lists_only_own_tenant_technicians|test_boss_cannot_update_other_tenant_technician|test_boss_cannot_deactivate_other_tenant_technician"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/UserController.php tests/Feature/MultiTenantIsolationTest.php
git commit -m "feat: CHG-002 tenant-isolate user list + mutation guards"
```

---

## Task 7: Tenant-filter technician dropdowns (closes CHG-015)

**Files:**
- Modify: `app/Http/Controllers/AppointmentController.php:86-89` (index `technicians`)
- Modify: `app/Http/Controllers/ServiceVisitController.php:76-79` (create `technicians`), `:166-169` (edit `technicians`)
- Test: `tests/Feature/MultiTenantIsolationTest.php`

- [ ] **Step 1: Write the failing test**

Add to `MultiTenantIsolationTest`:

```php
public function test_technician_dropdowns_are_tenant_filtered(): void
{
    $khalid = $this->boss();
    $saifzz = $this->boss();
    $kTech = $this->techFor($khalid, 'kdrop@example.com');
    $sTech = $this->techFor($saifzz, 'sdrop@example.com');

    // Appointment index dropdown
    $this->actingAs($khalid)->get(route('appointments.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('technicians',
            fn ($techs) => collect($techs)->pluck('id')->contains($kTech->id)
                && ! collect($techs)->pluck('id')->contains($sTech->id)));

    // Service record create dropdown
    $this->actingAs($khalid)->get(route('service-records.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('technicians',
            fn ($techs) => collect($techs)->pluck('id')->contains($kTech->id)
                && ! collect($techs)->pluck('id')->contains($sTech->id)));
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=test_technician_dropdowns_are_tenant_filtered`
Expected: FAIL — dropdowns include the other tenant's technician.

- [ ] **Step 3: Add tenant filter to all three dropdown queries**

`AppointmentController::index` (replace the `technicians` prop, lines 86-89):

```php
'technicians' => $request->user()->seesAllData()
    ? \App\Models\User::where('role', \App\Models\User::ROLE_TECHNICIAN)
        ->where('active', true)
        ->when($request->user()->tenantId(), fn ($q, $tid) => $q->where('tenant_id', $tid))
        ->orderBy('name')->get(['id', 'name'])
    : null,
```

`ServiceVisitController::create` (replace the `technicians` prop, lines 76-79):

```php
'technicians' => request()->user()->seesAllData()
    ? \App\Models\User::where('role', \App\Models\User::ROLE_TECHNICIAN)
        ->where('active', true)
        ->when(request()->user()->tenantId(), fn ($q, $tid) => $q->where('tenant_id', $tid))
        ->orderBy('name')->get(['id', 'name'])
    : null,
```

`ServiceVisitController::edit` (replace the `technicians` prop, lines 166-169) with the identical block as `create` above.

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=test_technician_dropdowns_are_tenant_filtered`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/AppointmentController.php app/Http/Controllers/ServiceVisitController.php tests/Feature/MultiTenantIsolationTest.php
git commit -m "feat: CHG-015 tenant-filter technician dropdowns"
```

---

## Task 8: ClientUnit tenant guard via parent client

**Files:**
- Modify: `app/Http/Controllers/ClientUnitController.php` — route-model-bind the client through `visibleTo`
- Test: `tests/Feature/MultiTenantIsolationTest.php`

- [ ] **Step 1: Write the failing test**

Add to `MultiTenantIsolationTest`:

```php
public function test_client_unit_index_blocked_cross_tenant(): void
{
    $khalid = $this->boss();
    $saifzz = $this->boss();
    $sClient = $this->clientFor($saifzz);

    $this->actingAs($khalid)->getJson(route('clients.units.index', $sClient))
        ->assertNotFound();
}
```

> Confirm the route name with `php artisan route:list --name=units`. Adjust `clients.units.index` to the actual name if different.

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=test_client_unit_index_blocked_cross_tenant`
Expected: FAIL — returns 200 (Khalid reads Saifzz's client units).

- [ ] **Step 3: Guard the parent client in every action**

In `app/Http/Controllers/ClientUnitController.php`, add a tenant check at the start of `index`, `store`, `update`, and `deactivate` by re-resolving the bound client through `visibleTo`. Replace each method's first line of work with a guard. For `index`:

```php
public function index(Client $client): JsonResponse
{
    abort_unless(Client::whereKey($client->getKey())->visibleTo(request()->user())->exists(), 404);

    $units = $client->units()->active()->orderBy('label')->get([
        'id', 'label', 'unit_type', 'hp', 'brand', 'model', 'serial_no', 'refrigerant_type', 'next_service_date',
    ]);

    return response()->json($units);
}
```

For `store`:

```php
public function store(StoreClientUnitRequest $request, Client $client): RedirectResponse
{
    abort_unless(Client::whereKey($client->getKey())->visibleTo($request->user())->exists(), 404);

    $client->units()->create($request->validated());

    return back()->with('success', 'Unit added.');
}
```

For `update` (keep the existing `client_id` match guard, add the tenant guard above it):

```php
public function update(UpdateClientUnitRequest $request, Client $client, ClientUnit $unit): RedirectResponse
{
    abort_unless(Client::whereKey($client->getKey())->visibleTo($request->user())->exists(), 404);
    abort_if($unit->client_id !== $client->id, 404);
    $unit->update($request->validated());

    return back()->with('success', 'Unit updated.');
}
```

For `deactivate`:

```php
public function deactivate(Client $client, ClientUnit $unit): RedirectResponse
{
    abort_unless(Client::whereKey($client->getKey())->visibleTo(request()->user())->exists(), 404);
    abort_if($unit->client_id !== $client->id, 404);
    $unit->update(['is_active' => false]);

    return back()->with('success', 'Unit deactivated.');
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=test_client_unit_index_blocked_cross_tenant`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ClientUnitController.php tests/Feature/MultiTenantIsolationTest.php
git commit -m "feat: CHG-002 tenant guard on client unit routes"
```

---

## Task 9: Tenant-scope reports + reminders

**Files:**
- Modify: `app/Services/Reminders/ReminderService.php:21` (`dueList` signature + both client joins)
- Modify: `app/Services/Reports/ReportService.php` — add `?int $tenantId = null` to `kpis`, `servicesByType`, `transactions`, `receivables`; apply tenant filters
- Modify: `app/Http/Controllers/DashboardController.php:29-46` (pass tenantId)
- Modify: `app/Http/Controllers/ReportController.php:22-25` (pass tenantId)
- Test: `tests/Feature/MultiTenantIsolationTest.php`

- [ ] **Step 1: Write the failing test**

Add to `MultiTenantIsolationTest`:

```php
private function paidVisitFor(\App\Models\User $boss, float $amount): void
{
    $client = $this->clientFor($boss);
    $visit = $client->visits()->create([
        'visit_date' => now()->toDateString(), 'warranty_months' => 0, 'total_amount' => $amount,
        'created_by' => $boss->id, 'technician_id' => null, 'tenant_id' => $boss->tenantId(),
    ]);
    $visit->transaction()->create([
        'txn_id' => 'TXN-'.now()->format('Ymd').'-'.str_pad((string) $visit->id, 3, '0', STR_PAD_LEFT),
        'amount' => $amount, 'method' => 'Cash', 'status' => 'paid', 'paid_at' => now(),
    ]);
}

public function test_report_revenue_is_tenant_scoped(): void
{
    $khalid = $this->boss();
    $saifzz = $this->boss();
    $this->paidVisitFor($khalid, 100.0);
    $this->paidVisitFor($saifzz, 999.0);

    $reports = app(\App\Services\Reports\ReportService::class);

    $kKpis = $reports->kpis(null, $khalid->tenantId());
    $this->assertSame(100.0, $kKpis['revenue_all_time']);
    $this->assertSame(1, $kKpis['total_clients']);

    $kTxns = $reports->transactions('all', 50, null, $khalid->tenantId());
    $this->assertCount(1, $kTxns);
    $this->assertSame(100.0, $kTxns[0]['amount']);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=test_report_revenue_is_tenant_scoped`
Expected: FAIL — `kpis()`/`transactions()` signatures don't accept the tenant argument (or revenue includes Saifzz's 999).

- [ ] **Step 3: Thread `tenantId` through reminders + reports**

`ReminderService::dueList` — change the signature and add a tenant filter to both client-joined queries:

```php
public function dueList(?int $tenantId = null): array
{
    $today = Carbon::today();
    $endOfMonth = $today->copy()->endOfMonth();

    $unitRows = DB::table('client_units as cu')
        ->join('clients as c', 'c.id', '=', 'cu.client_id')
        ->leftJoin('reminder_contacts as rc', 'rc.client_id', '=', 'c.id')
        ->whereNull('c.deleted_at')
        ->when($tenantId, fn ($q) => $q->where('c.tenant_id', $tenantId))
        ->where('cu.is_active', true)
        // ... rest unchanged
```

And on the legacy query, add the same `->when($tenantId, fn ($q) => $q->where('c.tenant_id', $tenantId))` immediately after its `->whereNull('c.deleted_at')`:

```php
    $legacyRows = DB::table('service_lines as sl')
        ->join('service_visits as sv', 'sv.id', '=', 'sl.visit_id')
        ->join('clients as c', 'c.id', '=', 'sv.client_id')
        ->leftJoin('reminder_contacts as rc', 'rc.client_id', '=', 'c.id')
        ->whereNull('c.deleted_at')
        ->when($tenantId, fn ($q) => $q->where('c.tenant_id', $tenantId))
        ->whereNotNull('sl.next_service_date')
        // ... rest unchanged
```

`ReportService` — add `?int $tenantId = null` as the **last** parameter to each public method and apply the filter. Updated signatures and the added filter lines:

`kpis`:

```php
public function kpis(?int $technicianId = null, ?int $tenantId = null): array
{
    // ... unchanged up to the $paidRevenue closure; add tenant filter inside it:
    $paidRevenue = function (Carbon $start, Carbon $end) use ($technicianId, $tenantId): float {
        $q = DB::table('transactions as t')
            ->join('service_visits as sv', 'sv.id', '=', 't.visit_id')
            ->where('t.status', 'paid')
            ->whereBetween('t.paid_at', [$start, $end]);
        if ($technicianId !== null) {
            $q->where('sv.technician_id', $technicianId);
        }
        if ($tenantId !== null) {
            $q->where('sv.tenant_id', $tenantId);
        }
        return (float) $q->sum('t.amount');
    };
```

In `kpis`, also add tenant to the all-time query:

```php
    $allTimeQ = DB::table('transactions as t')
        ->join('service_visits as sv', 'sv.id', '=', 't.visit_id')
        ->where('t.status', 'paid');
    if ($technicianId !== null) {
        $allTimeQ->where('sv.technician_id', $technicianId);
    }
    if ($tenantId !== null) {
        $allTimeQ->where('sv.tenant_id', $tenantId);
    }
    $revenueAllTime = (float) $allTimeQ->sum('t.amount');
```

In `kpis`, the admin branch (`$technicianId === null`) — tenant-scope client counts and reminders:

```php
    if ($technicianId === null) {
        $totalClients      = Client::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))->count();
        $clientsThisMonth  = Client::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereBetween('created_at', [$monthStart, $monthEnd])->count();
        $reminderStats     = $this->reminders->dueList($tenantId)['stats'];
        $pending           = $reminderStats['overdue'] + $reminderStats['due_this_month'];
    } else {
        $totalClients = (int) DB::table('service_visits')
            ->where('technician_id', $technicianId)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->distinct()->count('client_id');
        $clientsThisMonth = (int) DB::table('service_visits')
            ->where('technician_id', $technicianId)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereBetween('visit_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->distinct()->count('client_id');
        $pending = (int) DB::table('client_units')
            ->where('is_active', true)
            ->where('next_service_date', '<=', $now->copy()->endOfMonth()->toDateString())
            ->whereIn('client_id', function ($q) use ($technicianId, $tenantId) {
                $q->select('client_id')->from('service_visits')
                  ->where('technician_id', $technicianId)
                  ->when($tenantId, fn ($sq) => $sq->where('tenant_id', $tenantId))
                  ->distinct();
            })
            ->count();
    }
```

`servicesByType`:

```php
public function servicesByType(string $period, ?int $technicianId = null, ?int $tenantId = null): array
{
    [$from, $to] = $this->range($period);

    $q = DB::table('service_lines as sl')
        ->join('service_visits as sv', 'sv.id', '=', 'sl.visit_id');

    if ($technicianId !== null) {
        $q->where('sv.technician_id', $technicianId);
    }
    if ($tenantId !== null) {
        $q->where('sv.tenant_id', $tenantId);
    }
    // ... rest unchanged
```

`transactions`:

```php
public function transactions(string $period, ?int $limit = 50, ?int $technicianId = null, ?int $tenantId = null): array
{
    // ... after the existing `if ($technicianId !== null) { $q->where('sv.technician_id', ...); }`:
    if ($tenantId !== null) {
        $q->where('sv.tenant_id', $tenantId);
    }
    // ... rest unchanged
```

`receivables`:

```php
public function receivables(?int $technicianId = null, ?int $tenantId = null): array
{
    $today = now()->toDateString();

    $rows = DB::table('transactions as t')
        ->join('service_visits as sv', 'sv.id', '=', 't.visit_id')
        ->join('clients as c', 'c.id', '=', 'sv.client_id')
        ->whereNull('c.deleted_at')
        ->where('t.status', 'pending')
        ->when($technicianId !== null, fn ($q) => $q->where('sv.technician_id', $technicianId))
        ->when($tenantId !== null, fn ($q) => $q->where('sv.tenant_id', $tenantId))
        // ... rest unchanged
```

`DashboardController::index` — compute tenantId and pass it as the new last argument to each report call (replace lines 29-46):

```php
$user      = $request->user();
$scopeId   = $user->seesAllData() ? null : $user->id;
$tenantId  = $user->tenantId();
$canReport  = $user->hasPermission('view_reports');
$canCollect = $user->hasPermission('collect_payment');

return Inertia::render('Dashboard', [
    'canReport'    => $canReport,
    'period'       => $period,
    'month'        => $month,
    'report'       => [
        'kpis'           => $reports->kpis($scopeId, $tenantId),
        'servicesByType' => $reports->servicesByType($period, $scopeId, $tenantId),
        'transactions'   => $canReport
            ? $reports->transactions($period, 50, $scopeId, $tenantId)
            : [],
        'receivables'    => $canCollect
            ? $reports->receivables($scopeId, $tenantId)
            : null,
    ],
    'appointments' => Appointment::query()
        ->visibleTo($user)
        ->with('client:id,serial_no,name')
        ->forMonth($month)
        ->orderBy('datetime')
        ->get(),
]);
```

`ReportController::exportTransactions` — pass tenantId (replace lines 22-25):

```php
$user = $request->user();
$scopeId = $user->seesAllData() ? null : $user->id;

$rows = $reports->transactions($period, null, $scopeId, $user->tenantId());
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=test_report_revenue_is_tenant_scoped`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Reports/ReportService.php app/Services/Reminders/ReminderService.php app/Http/Controllers/DashboardController.php app/Http/Controllers/ReportController.php tests/Feature/MultiTenantIsolationTest.php
git commit -m "feat: CHG-002 tenant-scope reports and reminders"
```

---

## Task 10: Full-suite regression + cross-tenant read isolation + build

**Files:**
- Test: `tests/Feature/MultiTenantIsolationTest.php`

- [ ] **Step 1: Write the cross-tenant read-isolation test**

Add to `MultiTenantIsolationTest`:

```php
public function test_boss_cannot_read_other_tenant_service_record(): void
{
    $khalid = $this->boss();
    $saifzz = $this->boss();
    $visit = $this->visitFor($this->clientFor($saifzz), $saifzz);

    // Saifzz can open it; Khalid cannot (403 from the visibleTo guard).
    $this->actingAs($saifzz)->get(route('service-records.show', $visit))->assertOk();
    $this->actingAs($khalid)->get(route('service-records.show', $visit))->assertForbidden();
}

public function test_boss_cannot_read_other_tenant_client_profile(): void
{
    $khalid = $this->boss();
    $saifzz = $this->boss();
    $sClient = $this->clientFor($saifzz);

    // ClientController::show uses implicit binding; tenant guard returns 404.
    $this->actingAs($khalid)->get(route('clients.show', $sClient))->assertNotFound();
}
```

> `clients.show` currently has no guard — it loads any client by route binding. Add the guard in Step 3.

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter="test_boss_cannot_read_other_tenant_service_record|test_boss_cannot_read_other_tenant_client_profile"`
Expected: `test_boss_cannot_read_other_tenant_service_record` PASSES already (guard inherited). `test_boss_cannot_read_other_tenant_client_profile` FAILS (returns 200 — no guard on `clients.show`).

- [ ] **Step 3: Guard `ClientController::show`**

In `app/Http/Controllers/ClientController.php::show`, add a tenant guard as the first statement (before `$client->load(...)`):

```php
public function show(Client $client): Response
{
    $user = request()->user();
    abort_unless(Client::whereKey($client->getKey())->visibleTo($user)->exists(), 404);

    $client->load([
        // ... unchanged
```

(The `$user` variable already exists immediately below; remove the now-duplicate `$user = request()->user();` so it is assigned once.)

- [ ] **Step 4: Run the cross-tenant tests, then the FULL suite**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter="test_boss_cannot_read_other_tenant_service_record|test_boss_cannot_read_other_tenant_client_profile"`
Expected: PASS.

Then the entire suite:

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test`
Expected: PASS — all prior 233 tests plus the new `MultiTenantIsolationTest` cases green. If any prior test fails because it created an admin via factory that now collides with tenant logic, confirm the failure traces to a null-tenant fixture; the seam contract should keep them passing. Fix only genuine regressions; do not weaken isolation.

- [ ] **Step 5: Build the frontend bundle**

No Vue files changed in this plan, but run the build to keep the production manifest current (per project rule — port 5173 is not exposed):

Run: `docker compose exec -T laravel.test npm run build`
Expected: build completes, no errors.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/ClientController.php tests/Feature/MultiTenantIsolationTest.php
git commit -m "feat: CHG-002 guard client profile + full cross-tenant isolation suite"
```

---

## Post-implementation notes

- **Production deploy:** after merge, the four `tenant_id` migrations need `php artisan migrate`, then reseed (`php artisan db:seed` to self-root the existing Khalid/Saifzz accounts). Existing data is disposable (Q7) — if any non-tenant rows exist, run `migrate:fresh --seed` on production per the boss's go-ahead. **Confirm with the user before any production `migrate:fresh`.**
- **Closes:** CHG-002 (multi-boss isolation) and CHG-015 (tenant-filtered technician dropdown). Update `docs/FEEDBACK-13062026.md` to mark both TESTING when shipped.
- **Unblocks nothing else directly**, but CHG-011 (permission levels) layers cleanly on top of the tenant boundary.

## Self-review notes (addressed)

- **Spec coverage:** tenant model (T1-T2), stamping (T4), scope seam incl. Client (T3), client list/lookup (T5), user management rule 1 (T6), dropdowns/CHG-015 (T7), client units (T8), reports+reminders (T9), cross-tenant read isolation + portal-left-alone + full suite (T10). Portal intentionally untouched per spec.
- **Type consistency:** `tenantId(): ?int` used everywhere; ReportService methods all take `?int $tenantId` as final param; `dueList(?int $tenantId = null)`.
- **Seam contract** (null-tenant bypass) stated once up top and echoed in each scope/report method to keep the 233-test suite green.
