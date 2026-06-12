# Dynamic Service Types Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace three hardcoded `SERVICE_TYPES` PHP constants with a `service_types` DB table managed via a new settings page accessible to admins and technicians.

**Architecture:** A single `service_types` table is the source of truth. A new `ServiceTypeController` handles CRUD (no delete). All existing `Rule::in(self::SERVICE_TYPES)` validators swap to `Rule::exists('service_types', 'name')`. Controllers that pass service types to Inertia props swap the const reference to `ServiceType::pluck('name')->all()`.

**Tech Stack:** Laravel 11, Inertia.js, Vue 3, Tabler Icons, PHPUnit (via Docker)

**Test runner:** `docker exec saifzz-aircond-laravel.test-1 php artisan test`
**Targeted test run:** `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=ServiceTypeTest`
**Full suite:** `docker exec saifzz-aircond-laravel.test-1 php artisan test`

---

## File Map

| Action | Path | Purpose |
|--------|------|---------|
| Create | `database/migrations/2026_06_12_000090_create_service_types_table.php` | `service_types` table |
| Create | `app/Models/ServiceType.php` | Eloquent model |
| Create | `database/seeders/ServiceTypeSeeder.php` | Seed 6 initial types |
| Create | `app/Http/Controllers/ServiceTypeController.php` | index / store / update |
| Create | `tests/Feature/ServiceTypeTest.php` | Controller + validation tests |
| Create | `resources/js/Pages/ServiceTypes/Index.vue` | Management UI |
| Modify | `database/seeders/DatabaseSeeder.php` | Call ServiceTypeSeeder |
| Modify | `app/Models/User.php` | Add `manage_service_types` permission |
| Modify | `app/Models/Appointment.php` | Remove SERVICE_TYPES const |
| Modify | `app/Http/Requests/StoreAppointmentRequest.php` | Rule::exists |
| Modify | `app/Http/Requests/StoreServiceVisitRequest.php` | Rule::exists, remove const |
| Modify | `app/Http/Requests/StoreServiceFeeRequest.php` | Rule::exists, remove const |
| Modify | `app/Http/Controllers/AppointmentController.php` | ServiceType::pluck |
| Modify | `app/Http/Controllers/ServiceVisitController.php` | ServiceType::pluck |
| Modify | `app/Http/Controllers/ServiceFeeController.php` | ServiceType::pluck |
| Modify | `routes/web.php` | Add service-types routes |
| Modify | `resources/js/Layouts/AdminLayout.vue` | Add nav entry |

---

## Task 1: Migration, Model, and Seeder

**Files:**
- Create: `database/migrations/2026_06_12_000090_create_service_types_table.php`
- Create: `app/Models/ServiceType.php`
- Create: `database/seeders/ServiceTypeSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`

- [ ] **Step 1: Create the migration**

```php
<?php
// database/migrations/2026_06_12_000090_create_service_types_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_types');
    }
};
```

- [ ] **Step 2: Create the model**

```php
<?php
// app/Models/ServiceType.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceType extends Model
{
    protected $fillable = ['name'];
}
```

- [ ] **Step 3: Create the seeder**

```php
<?php
// database/seeders/ServiceTypeSeeder.php

namespace Database\Seeders;

use App\Models\ServiceType;
use Illuminate\Database\Seeder;

class ServiceTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = ['Cleaning', 'Gas Top-Up', 'Repair', 'Installation', 'Troubleshoot', 'Dismantle'];

        foreach ($types as $name) {
            ServiceType::firstOrCreate(['name' => $name]);
        }
    }
}
```

- [ ] **Step 4: Register seeder in DatabaseSeeder**

In `database/seeders/DatabaseSeeder.php`, add after `$this->call(ServiceFeeSeeder::class);`:

```php
$this->call(ServiceTypeSeeder::class);
```

- [ ] **Step 5: Run the migration and seeder**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan migrate
docker exec saifzz-aircond-laravel.test-1 php artisan db:seed --class=ServiceTypeSeeder
```

Expected: migration runs, 6 rows inserted.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_12_000090_create_service_types_table.php \
        app/Models/ServiceType.php \
        database/seeders/ServiceTypeSeeder.php \
        database/seeders/DatabaseSeeder.php
git commit -m "feat(service-types): add service_types table, model, and seeder"
```

---

## Task 2: Permission

**Files:**
- Modify: `app/Models/User.php`

- [ ] **Step 1: Add permission to User model**

In `app/Models/User.php`, update `PERMISSIONS` array — add `'manage_service_types'`:

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
    'manage_service_types',
];
```

Update `DEFAULT_TECHNICIAN_PERMISSIONS` — add `'manage_service_types'`:

```php
public const DEFAULT_TECHNICIAN_PERMISSIONS = [
    'view_clients',
    'record_service',
    'set_appointment',
    'manage_service_types',
];
```

- [ ] **Step 2: Run full test suite — confirm no regressions**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test
```

Expected: all tests pass.

- [ ] **Step 3: Commit**

```bash
git add app/Models/User.php
git commit -m "feat(service-types): add manage_service_types permission"
```

---

## Task 3: ServiceTypeController + Routes + Tests

**Files:**
- Create: `app/Http/Controllers/ServiceTypeController.php`
- Create: `tests/Feature/ServiceTypeTest.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Feature/ServiceTypeTest.php

namespace Tests\Feature;

use App\Models\ServiceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceTypeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function tech(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
            'permissions' => User::DEFAULT_TECHNICIAN_PERMISSIONS,
        ]);
    }

    public function test_index_renders_for_admin(): void
    {
        ServiceType::create(['name' => 'Cleaning']);

        $this->actingAs($this->admin())
            ->get('/service-types')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('ServiceTypes/Index')
                ->has('serviceTypes', 1)
            );
    }

    public function test_index_renders_for_technician(): void
    {
        ServiceType::create(['name' => 'Cleaning']);

        $this->actingAs($this->tech())
            ->get('/service-types')
            ->assertOk();
    }

    public function test_unauthenticated_redirected(): void
    {
        $this->get('/service-types')->assertRedirect('/login');
    }

    public function test_store_creates_type(): void
    {
        $this->actingAs($this->admin())
            ->post('/service-types', ['name' => 'Dismantle'])
            ->assertRedirect();

        $this->assertDatabaseHas('service_types', ['name' => 'Dismantle']);
    }

    public function test_store_rejects_duplicate_name(): void
    {
        ServiceType::create(['name' => 'Cleaning']);

        $this->actingAs($this->admin())
            ->post('/service-types', ['name' => 'Cleaning'])
            ->assertSessionHasErrors(['name']);
    }

    public function test_store_rejects_empty_name(): void
    {
        $this->actingAs($this->admin())
            ->post('/service-types', ['name' => ''])
            ->assertSessionHasErrors(['name']);
    }

    public function test_update_renames_type(): void
    {
        $type = ServiceType::create(['name' => 'Cleaning']);

        $this->actingAs($this->admin())
            ->put("/service-types/{$type->id}", ['name' => 'Deep Clean'])
            ->assertRedirect();

        $this->assertDatabaseHas('service_types', ['id' => $type->id, 'name' => 'Deep Clean']);
    }

    public function test_update_rejects_name_taken_by_other(): void
    {
        $a = ServiceType::create(['name' => 'Cleaning']);
        ServiceType::create(['name' => 'Repair']);

        $this->actingAs($this->admin())
            ->put("/service-types/{$a->id}", ['name' => 'Repair'])
            ->assertSessionHasErrors(['name']);
    }

    public function test_update_allows_same_name_on_self(): void
    {
        $type = ServiceType::create(['name' => 'Cleaning']);

        $this->actingAs($this->admin())
            ->put("/service-types/{$type->id}", ['name' => 'Cleaning'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    public function test_no_destroy_route(): void
    {
        $type = ServiceType::create(['name' => 'Cleaning']);

        $this->actingAs($this->admin())
            ->delete("/service-types/{$type->id}")
            ->assertStatus(405); // Method Not Allowed
    }
}
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=ServiceTypeTest
```

Expected: FAIL — route not found.

- [ ] **Step 3: Create the controller**

```php
<?php
// app/Http/Controllers/ServiceTypeController.php

namespace App\Http\Controllers;

use App\Models\ServiceType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ServiceTypeController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('ServiceTypes/Index', [
            'serviceTypes' => ServiceType::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:service_types,name'],
        ]);

        ServiceType::create(['name' => $request->input('name')]);

        return back()->with('success', 'Service type added.');
    }

    public function update(Request $request, ServiceType $serviceType): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:100', "unique:service_types,name,{$serviceType->id}"],
        ]);

        $serviceType->update(['name' => $request->input('name')]);

        return back()->with('success', 'Service type updated.');
    }
}
```

- [ ] **Step 4: Add routes to `routes/web.php`**

Add after the fees route group (around line 63), before Users group:

```php
// Service Types (manage_service_types — admin + technician)
Route::middleware('can:manage_service_types')->group(function () {
    Route::get('service-types', [ServiceTypeController::class, 'index'])->name('service-types.index');
    Route::post('service-types', [ServiceTypeController::class, 'store'])->name('service-types.store');
    Route::put('service-types/{serviceType}', [ServiceTypeController::class, 'update'])->name('service-types.update');
});
```

Add the import at the top of `routes/web.php`:

```php
use App\Http\Controllers\ServiceTypeController;
```

- [ ] **Step 5: Run tests to confirm they pass**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=ServiceTypeTest
```

Expected: all 9 tests pass.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/ServiceTypeController.php \
        tests/Feature/ServiceTypeTest.php \
        routes/web.php
git commit -m "feat(service-types): add ServiceTypeController, routes, and tests"
```

---

## Task 4: Replace Hardcoded Constants in Validators

**Files:**
- Modify: `app/Http/Requests/StoreAppointmentRequest.php`
- Modify: `app/Http/Requests/StoreServiceVisitRequest.php`
- Modify: `app/Http/Requests/StoreServiceFeeRequest.php`
- Modify: `app/Models/Appointment.php`

- [ ] **Step 1: Update `StoreAppointmentRequest`**

Replace the `use App\Models\Appointment;` import with `use App\Models\ServiceType;` (keep other imports).

Change line 26:
```php
// Before
'service_type' => ['required', Rule::in(Appointment::SERVICE_TYPES)],

// After
'service_type' => ['required', 'string', Rule::exists('service_types', 'name')],
```

Remove the `use App\Models\Appointment;` import since it's no longer needed.

- [ ] **Step 2: Update `StoreServiceVisitRequest`**

Remove `public const SERVICE_TYPES = ['Cleaning', 'Gas Top-Up', 'Repair', 'Installation', 'Troubleshoot'];` from the bottom of the file.

Change the validation rule for `lines.*.service_type`:
```php
// Before
'lines.*.service_type' => ['required', Rule::in(self::SERVICE_TYPES)],

// After
'lines.*.service_type' => ['required', 'string', Rule::exists('service_types', 'name')],
```

- [ ] **Step 3: Update `StoreServiceFeeRequest`**

Remove `public const SERVICE_TYPES = ['Cleaning', 'Gas Top-Up', 'Repair', 'Installation', 'Troubleshoot'];` from the bottom of the file.

Change the validation rule for `service_type`:
```php
// Before
'service_type' => ['required', Rule::in(self::SERVICE_TYPES)],

// After
'service_type' => ['required', 'string', Rule::exists('service_types', 'name')],
```

- [ ] **Step 4: Remove the constant from `Appointment` model**

In `app/Models/Appointment.php`, remove line 16:
```php
/** Service types an appointment can be booked for (mirrors the fee book). */
public const SERVICE_TYPES = ['Cleaning', 'Gas Top-Up', 'Repair', 'Installation', 'Troubleshoot'];
```

- [ ] **Step 5: Run full test suite**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test
```

Expected: all tests pass. (Existing tests use inline strings like `'Cleaning'` which will be in the DB via the seeder.)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Requests/StoreAppointmentRequest.php \
        app/Http/Requests/StoreServiceVisitRequest.php \
        app/Http/Requests/StoreServiceFeeRequest.php \
        app/Models/Appointment.php
git commit -m "feat(service-types): replace Rule::in constants with Rule::exists"
```

---

## Task 5: Replace Constant References in Controllers

**Files:**
- Modify: `app/Http/Controllers/AppointmentController.php`
- Modify: `app/Http/Controllers/ServiceVisitController.php`
- Modify: `app/Http/Controllers/ServiceFeeController.php`

- [ ] **Step 1: Update `AppointmentController`**

Find line: `'serviceTypes' => Appointment::SERVICE_TYPES,`

Replace with:
```php
'serviceTypes' => \App\Models\ServiceType::orderBy('name')->pluck('name')->all(),
```

If `Appointment::SERVICE_TYPES` was the only reason for the `use App\Models\Appointment;` import, check if `Appointment` is still used elsewhere in the controller — it is (for `Appointment::TRANSITIONS`, `Appointment::STATUSES`, etc.), so keep the import.

Add import at top of file if not present:
```php
use App\Models\ServiceType;
```

Then use without backslash:
```php
'serviceTypes' => ServiceType::orderBy('name')->pluck('name')->all(),
```

- [ ] **Step 2: Update `ServiceVisitController`**

Find line: `'serviceTypes' => StoreServiceVisitRequest::SERVICE_TYPES,`

Replace with:
```php
'serviceTypes' => ServiceType::orderBy('name')->pluck('name')->all(),
```

Add import at top of file:
```php
use App\Models\ServiceType;
```

- [ ] **Step 3: Update `ServiceFeeController`**

Find line: `'serviceTypes' => StoreServiceFeeRequest::SERVICE_TYPES,`

Replace with:
```php
'serviceTypes' => ServiceType::orderBy('name')->pluck('name')->all(),
```

Add import at top of file:
```php
use App\Models\ServiceType;
```

- [ ] **Step 4: Run full test suite**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test
```

Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/AppointmentController.php \
        app/Http/Controllers/ServiceVisitController.php \
        app/Http/Controllers/ServiceFeeController.php
git commit -m "feat(service-types): controllers read types from DB"
```

---

## Task 6: Frontend — Service Types Management Page

**Files:**
- Create: `resources/js/Pages/ServiceTypes/Index.vue`

- [ ] **Step 1: Create the page**

```vue
<!-- resources/js/Pages/ServiceTypes/Index.vue -->
<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { IconPencil, IconCheck, IconX, IconPlus } from '@tabler/icons-vue';

const props = defineProps({
    serviceTypes: Array,
});

// ── Add form ────────────────────────────────────────────────────────────────
const addForm = useForm({ name: '' });
const showAdd = ref(false);

function submitAdd() {
    addForm.post(route('service-types.store'), {
        onSuccess: () => { addForm.reset(); showAdd.value = false; },
    });
}

// ── Edit form ────────────────────────────────────────────────────────────────
const editingId = ref(null);
const editForm = useForm({ name: '' });

function startEdit(type) {
    editingId.value = type.id;
    editForm.name = type.name;
}

function cancelEdit() {
    editingId.value = null;
    editForm.reset();
}

function submitEdit(type) {
    editForm.put(route('service-types.update', type.id), {
        onSuccess: () => { editingId.value = null; },
    });
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <PageHeader title="Service Types" />
        </template>

        <div class="mx-auto max-w-2xl px-4 py-8 sm:px-6 lg:px-8">
            <Card>
                <div class="divide-y divide-line">
                    <div
                        v-for="type in serviceTypes"
                        :key="type.id"
                        class="flex items-center gap-3 px-4 py-3"
                    >
                        <!-- View mode -->
                        <template v-if="editingId !== type.id">
                            <span class="flex-1 text-sm font-medium text-ink">{{ type.name }}</span>
                            <button
                                type="button"
                                class="rounded p-1 text-ink-muted hover:text-primary"
                                @click="startEdit(type)"
                            >
                                <IconPencil class="h-4 w-4" />
                            </button>
                        </template>

                        <!-- Edit mode -->
                        <template v-else>
                            <input
                                v-model="editForm.name"
                                class="flex-1 rounded-ra border-line bg-surface px-3 py-1.5 text-sm text-ink focus:border-primary focus:ring-primary"
                                @keyup.enter="submitEdit(type)"
                                @keyup.escape="cancelEdit"
                            />
                            <p v-if="editForm.errors.name" class="text-xs text-danger">{{ editForm.errors.name }}</p>
                            <button
                                type="button"
                                class="rounded p-1 text-success hover:text-success/80"
                                :disabled="editForm.processing"
                                @click="submitEdit(type)"
                            >
                                <IconCheck class="h-4 w-4" />
                            </button>
                            <button
                                type="button"
                                class="rounded p-1 text-ink-muted hover:text-danger"
                                @click="cancelEdit"
                            >
                                <IconX class="h-4 w-4" />
                            </button>
                        </template>
                    </div>

                    <!-- Add row -->
                    <div class="px-4 py-3">
                        <template v-if="!showAdd">
                            <button
                                type="button"
                                class="flex items-center gap-1.5 text-sm text-primary hover:underline"
                                @click="showAdd = true"
                            >
                                <IconPlus class="h-4 w-4" />
                                Add type
                            </button>
                        </template>
                        <template v-else>
                            <div class="flex items-center gap-3">
                                <input
                                    v-model="addForm.name"
                                    placeholder="Type name…"
                                    class="flex-1 rounded-ra border-line bg-surface px-3 py-1.5 text-sm text-ink focus:border-primary focus:ring-primary"
                                    @keyup.enter="submitAdd"
                                    @keyup.escape="showAdd = false; addForm.reset()"
                                />
                                <button
                                    type="button"
                                    class="rounded p-1 text-success hover:text-success/80"
                                    :disabled="addForm.processing"
                                    @click="submitAdd"
                                >
                                    <IconCheck class="h-4 w-4" />
                                </button>
                                <button
                                    type="button"
                                    class="rounded p-1 text-ink-muted hover:text-danger"
                                    @click="showAdd = false; addForm.reset()"
                                >
                                    <IconX class="h-4 w-4" />
                                </button>
                            </div>
                            <p v-if="addForm.errors.name" class="mt-1 text-xs text-danger">{{ addForm.errors.name }}</p>
                        </template>
                    </div>
                </div>
            </Card>
        </div>
    </AdminLayout>
</template>
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/Pages/ServiceTypes/Index.vue
git commit -m "feat(service-types): add ServiceTypes management page"
```

---

## Task 7: Nav Entry

**Files:**
- Modify: `resources/js/Layouts/AdminLayout.vue`

- [ ] **Step 1: Add import for IconCategory**

In `AdminLayout.vue`, add `IconCategory` to the tabler icons import:

```js
import {
    IconLayoutDashboard, IconUsers, IconBell, IconClipboardPlus,
    IconCurrencyDollar, IconCalendarEvent, IconQrcode, IconUserCog,
    IconAirConditioning, IconLogout, IconMenu2, IconCategory,
} from '@tabler/icons-vue';
```

- [ ] **Step 2: Add nav item to Management section**

In the `sections` computed, add after the Appointments entry:

```js
{ label: 'Service Types', route: 'service-types.index', match: 'service-types', icon: IconCategory, permission: 'manage_service_types' },
```

The Management section should look like:

```js
{ title: 'Management', items: [
    { label: 'Service Fees', route: 'fees.index', match: 'fees', icon: IconCurrencyDollar, permission: 'edit_fees' },
    { label: 'Appointments', route: 'appointments.index', match: 'appointments', icon: IconCalendarEvent, permission: 'set_appointment' },
    { label: 'Service Types', route: 'service-types.index', match: 'service-types', icon: IconCategory, permission: 'manage_service_types' },
    { label: 'Users', route: 'users.index', match: 'users', icon: IconUserCog, permission: 'manage_users' },
]},
```

- [ ] **Step 3: Verify `IconCategory` exists in @tabler/icons-vue**

```bash
grep -r "IconCategory" "//wsl.localhost/Ubuntu/home/hamid/Saifzz-Aircond/node_modules/@tabler/icons-vue/dist/index.mjs" | head -1
```

If not found, use `IconTag` or `IconList` instead (same import swap in both places above).

- [ ] **Step 4: Run full test suite**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test
```

Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Layouts/AdminLayout.vue
git commit -m "feat(service-types): add Service Types nav entry"
```

---

## Task 8: Eyeball Test

- [ ] **Step 1: Start dev server**

```bash
cd //wsl.localhost/Ubuntu/home/hamid/Saifzz-Aircond && npm run dev
```

- [ ] **Step 2: Verify in browser**
  - Visit `/service-types` — list shows 6 types
  - Edit a type name → save → name updates in list
  - Add a new type → appears in list
  - Try duplicate name → inline error shown
  - Visit `/appointments` → service type dropdown includes "Dismantle"
  - Visit `/service-records/create` → service type dropdown includes "Dismantle"
  - Visit `/fees` → service type dropdown includes "Dismantle"
  - Technician login: "Service Types" appears in sidebar

- [ ] **Step 3: Final commit tag**

```bash
git log --oneline -8
```

Confirm all 7 feature commits are present.
