# Service Settings Merge (CHG-004) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Merge Service Types and Service Fees into one "Service Settings" page at `/service-types`, with two action buttons ("New Service Type" / "Set Fee") each opening their own modal.

**Architecture:** `ServiceTypeController::index` absorbs the fee query; the existing `FeeModal` component moves into `ServiceTypes/Partials/`; `Fees/Index.vue` is deleted; `fees.index` GET becomes a redirect to `service-types.index`; the sidebar loses the "Service Fees" entry and renames the remaining entry to "Service Settings".

**Tech Stack:** Laravel (Inertia), Vue 3 Composition API, Tabler icons, Tailwind CSS

---

### Task 1: Move FeeModal into ServiceTypes/Partials/

**Files:**
- Create: `resources/js/Pages/ServiceTypes/Partials/FeeModal.vue` (copy of Fees/Partials/FeeModal.vue — content unchanged)

- [ ] **Step 1: Create directory and copy file**

```bash
mkdir -p resources/js/Pages/ServiceTypes/Partials
cp resources/js/Pages/Fees/Partials/FeeModal.vue resources/js/Pages/ServiceTypes/Partials/FeeModal.vue
```

- [ ] **Step 2: Verify copy**

```bash
head -5 resources/js/Pages/ServiceTypes/Partials/FeeModal.vue
```
Expected: `<script setup>` on line 1.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/ServiceTypes/Partials/FeeModal.vue
git commit -m "refactor: copy FeeModal to ServiceTypes/Partials (CHG-004 prep)"
```

---

### Task 2: Update ServiceTypeController to pass fee data

**Files:**
- Modify: `app/Http/Controllers/ServiceTypeController.php`

- [ ] **Step 1: Add imports and update index()**

Replace the top of `ServiceTypeController.php` (the two `use` lines after `namespace`) with the updated version, and replace the `index()` method body:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreServiceFeeRequest;
use App\Models\ServiceFee;
use App\Models\ServiceType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ServiceTypeController extends Controller
{
    public function index(): Response
    {
        $fees = ServiceFee::orderBy('service_type')->orderBy('option')->get();

        return Inertia::render('ServiceTypes/Index', [
            'serviceTypes' => ServiceType::orderBy('name')->get(['id', 'name', 'requires_next_service']),
            'feeGroups'    => $fees->groupBy('service_type'),
            'modes'        => StoreServiceFeeRequest::MODES,
        ]);
    }
```

Leave `store()` and `update()` methods unchanged.

- [ ] **Step 2: Verify no syntax errors**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan route:list --name=service-types 2>&1 | head -5
```
Expected: table rows for service-types.index/store/update with no PHP error output.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/ServiceTypeController.php
git commit -m "feat: CHG-004 ServiceTypeController index passes feeGroups + modes"
```

---

### Task 3: Rewrite ServiceTypes/Index.vue as merged page

**Files:**
- Modify: `resources/js/Pages/ServiceTypes/Index.vue`

- [ ] **Step 1: Replace the entire file**

```vue
<script setup>
import { computed, ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Card from '@/Components/Card.vue';
import Badge from '@/Components/Badge.vue';
import FeeModal from './Partials/FeeModal.vue';
import { useForm, router } from '@inertiajs/vue3';
import { IconPencil, IconCheck, IconX, IconPlus } from '@tabler/icons-vue';
import { serviceVariant } from '@/lib/badges';
import { confirmDanger } from '@/lib/swal';

const props = defineProps({
    serviceTypes: Array,
    feeGroups: Object,
    modes: Array,
});

// --- Service Types ---
const addForm = useForm({ name: '' });
const showAdd = ref(false);

function submitAdd() {
    addForm.post(route('service-types.store'), {
        onSuccess: () => { addForm.reset(); showAdd.value = false; },
    });
}

const editingId = ref(null);
const editForm = useForm({ name: '', requires_next_service: false });

function startEdit(type) {
    editingId.value = type.id;
    editForm.name = type.name;
    editForm.requires_next_service = type.requires_next_service;
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

function toggleNextService(type) {
    router.put(route('service-types.update', type.id), {
        name: type.name,
        requires_next_service: !type.requires_next_service,
    }, { preserveScroll: true });
}

// --- Fees ---
const modalOpen = ref(false);
const editing = ref(null);

const openAdd = () => { editing.value = null; modalOpen.value = true; };
const openEdit = (fee) => { editing.value = fee; modalOpen.value = true; };

const remove = async (fee) => {
    const label = fee.service_type + (fee.option ? ' · ' + fee.option : '');
    const ok = await confirmDanger({
        title: 'Delete this fee?',
        body: `<strong>${label}</strong><br>Existing records keep their snapshotted price.`,
        confirmText: 'Delete',
    });
    if (ok) {
        router.delete(route('fees.destroy', fee.id), { preserveScroll: true });
    }
};

const money = (v) => v == null ? '—' : 'RM ' + Number(v).toFixed(2);
const modeLabel = { fixed_per_unit: 'per unit', flexible: 'Flexible' };
const serviceTypeNames = computed(() => props.serviceTypes.map((t) => t.name));
</script>

<template>
    <AdminLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <h1 class="text-base font-bold text-navy-800">Service Settings</h1>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-ra border border-line bg-surface px-3 py-1.5 text-sm font-medium text-ink shadow-card hover:bg-surface-muted"
                        @click="showAdd = true"
                    >
                        <IconPlus class="h-4 w-4" />
                        New Service Type
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-ra bg-primary px-3 py-1.5 text-sm font-semibold text-white shadow-card hover:bg-primary-hover"
                        @click="openAdd"
                    >
                        <IconPlus class="h-4 w-4" />
                        Set Fee
                    </button>
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-3xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
            <!-- Service Types section -->
            <Card title="Service Types">
                <div class="divide-y divide-line">
                    <div
                        v-for="type in serviceTypes"
                        :key="type.id"
                        class="flex items-center gap-3 px-4 py-3"
                    >
                        <template v-if="editingId !== type.id">
                            <span class="flex-1 text-sm font-medium text-ink">{{ type.name }}</span>
                            <button
                                type="button"
                                class="flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium transition"
                                :class="type.requires_next_service ? 'bg-primary-50 text-primary' : 'bg-surface-muted text-ink-soft'"
                                @click="toggleNextService(type)"
                            >
                                <span
                                    class="h-3.5 w-3.5 rounded-full border-2 transition"
                                    :class="type.requires_next_service ? 'border-primary bg-primary' : 'border-ink-muted bg-transparent'"
                                />
                                {{ type.requires_next_service ? 'Next service' : 'No follow-up' }}
                            </button>
                            <button
                                type="button"
                                class="rounded p-1 text-ink-muted hover:text-primary"
                                @click="startEdit(type)"
                            >
                                <IconPencil class="h-4 w-4" />
                            </button>
                        </template>

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

            <!-- Fee Schedule section -->
            <div>
                <div class="mb-5 flex gap-3 rounded-ral border border-primary/20 bg-primary-50 px-4 py-3.5 text-sm text-primary">
                    <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" /><path d="M12 8v4M12 16h.01" stroke-linecap="round" /></svg>
                    <span>
                        Rates here are <strong>auto-applied</strong> when a technician picks a service type and unit type on a job.
                        Gas Top-Up entries are billed by PSI level; Repair jobs use <strong>flexible pricing</strong> set per-job by the technician.
                        Changes only affect future service lines — past records keep their snapshotted rate.
                    </span>
                </div>

                <Card title="Fee Schedule">
                    <div v-if="Object.keys(feeGroups).length === 0" class="py-8 text-center text-sm text-ink-soft">
                        No fee entries yet. Add your first fee entry to get started.
                    </div>
                    <table v-else class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-line text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">
                                <th class="pb-2.5 pr-4">Service Type</th>
                                <th class="pb-2.5 pr-4">Unit / Option</th>
                                <th class="pb-2.5 pr-4">Fee</th>
                                <th class="pb-2.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <template v-for="(fees, type) in feeGroups" :key="type">
                                <tr v-for="(f, idx) in fees" :key="f.id" class="group">
                                    <td class="py-3 pr-4 align-middle">
                                        <Badge v-if="idx === 0" :variant="serviceVariant(type)">{{ type }}</Badge>
                                    </td>
                                    <td class="py-3 pr-4 align-middle font-medium text-ink">
                                        {{ f.option || 'Flat job' }}
                                    </td>
                                    <td class="py-3 pr-4 align-middle">
                                        <Badge v-if="f.pricing_mode === 'flexible'" variant="amber">Flexible</Badge>
                                        <span v-else class="font-mono font-semibold text-navy-800">
                                            {{ money(f.rate) }}<span class="ml-1 text-xs font-normal text-ink-soft">/ {{ modeLabel[f.pricing_mode] ?? f.pricing_mode }}</span>
                                        </span>
                                    </td>
                                    <td class="py-3 align-middle text-right">
                                        <div class="flex items-center justify-end gap-3">
                                            <button class="text-sm font-medium text-primary hover:text-primary-hover" @click="openEdit(f)">Edit</button>
                                            <button class="text-sm font-medium text-danger hover:underline" @click="remove(f)">Delete</button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </Card>
            </div>
        </div>

        <FeeModal
            :open="modalOpen"
            :fee="editing"
            :service-types="serviceTypeNames"
            :modes="modes"
            @close="modalOpen = false"
        />
    </AdminLayout>
</template>
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/Pages/ServiceTypes/Index.vue
git commit -m "feat: CHG-004 merge fee schedule into Service Settings page"
```

---

### Task 4: Update routes/web.php — redirect fees.index

**Files:**
- Modify: `routes/web.php`

- [ ] **Step 1: Move fees.index GET to a redirect, keep mutation routes gated**

Replace the Service Fees route block (lines 77–83) with:

```php
    // Service Fees (module 3) — GET /fees redirects to merged Service Settings page.
    Route::redirect('fees', '/service-types')->name('fees.index');
    Route::middleware('can:edit_fees')->group(function () {
        Route::post('fees', [ServiceFeeController::class, 'store'])->name('fees.store');
        Route::put('fees/{fee}', [ServiceFeeController::class, 'update'])->name('fees.update');
        Route::delete('fees/{fee}', [ServiceFeeController::class, 'destroy'])->name('fees.destroy');
    });
```

- [ ] **Step 2: Verify route list**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan route:list --name=fees 2>&1
```
Expected: 4 rows — `fees.index` (GET, redirect), `fees.store` (POST), `fees.update` (PUT), `fees.destroy` (DELETE). No PHP errors.

- [ ] **Step 3: Commit**

```bash
git add routes/web.php
git commit -m "feat: CHG-004 redirect GET /fees to /service-types"
```

---

### Task 5: Update AdminLayout.vue sidebar

**Files:**
- Modify: `resources/js/Layouts/AdminLayout.vue`

- [ ] **Step 1: Remove IconCurrencyDollar from import and fees nav entry**

In the `<script setup>` block, change the icon import line from:

```js
import {
    IconLayoutDashboard, IconUsers, IconBell, IconClipboardPlus,
    IconCurrencyDollar, IconCalendarEvent, IconUserCog,
    IconAirConditioning, IconLogout, IconMenu2, IconCategory,
} from '@tabler/icons-vue';
```

to:

```js
import {
    IconLayoutDashboard, IconUsers, IconBell, IconClipboardPlus,
    IconCalendarEvent, IconUserCog,
    IconAirConditioning, IconLogout, IconMenu2, IconCategory,
} from '@tabler/icons-vue';
```

- [ ] **Step 2: Update the Settings section nav items**

In the `sections` computed, change the Settings section from:

```js
        { title: 'Settings', items: [
            { label: 'Service Types', route: 'service-types.index', match: 'service-types', icon: IconCategory, permission: 'manage_service_types', adminOnly: true },
            { label: 'Service Fees', route: 'fees.index', match: 'fees', icon: IconCurrencyDollar, permission: 'edit_fees', adminOnly: true },
            { label: 'Users', route: 'users.index', match: 'users', icon: IconUserCog, permission: 'manage_users', adminOnly: true },
            { label: 'Clients', route: 'clients.index', match: 'clients', icon: IconUsers, permission: 'view_clients', adminOnly: true },
        ]},
```

to:

```js
        { title: 'Settings', items: [
            { label: 'Service Settings', route: 'service-types.index', match: 'service-types', icon: IconCategory, permission: 'manage_service_types', adminOnly: true },
            { label: 'Users', route: 'users.index', match: 'users', icon: IconUserCog, permission: 'manage_users', adminOnly: true },
            { label: 'Clients', route: 'clients.index', match: 'clients', icon: IconUsers, permission: 'view_clients', adminOnly: true },
        ]},
```

- [ ] **Step 3: Commit**

```bash
git add resources/js/Layouts/AdminLayout.vue
git commit -m "feat: CHG-004 rename sidebar entry to Service Settings, remove Service Fees entry"
```

---

### Task 6: Update ServiceFeeTest.php — fix two assertions broken by the redirect

**Files:**
- Modify: `tests/Feature/ServiceFeeTest.php`

The `fees.index` GET is now a redirect (302 → `/service-types`). Two tests assert the wrong status:

- `test_technician_without_edit_fees_is_forbidden` — previously 403, now 302
- `test_admin_can_view_price_book` — previously 200, now 302

`test_guest_is_redirected` stays correct: the `auth` middleware fires first and still redirects the guest to `/login` before the redirect route fires.

- [ ] **Step 1: Update the two affected assertions**

In `tests/Feature/ServiceFeeTest.php`, replace:

```php
    public function test_technician_without_edit_fees_is_forbidden(): void
    {
        $this->actingAs($this->techWithoutFees())
            ->get(route('fees.index'))
            ->assertForbidden();
    }

    public function test_admin_can_view_price_book(): void
    {
        $this->actingAs($this->admin())->get(route('fees.index'))->assertOk();
    }
```

with:

```php
    public function test_technician_without_edit_fees_is_redirected_to_service_settings(): void
    {
        $this->actingAs($this->techWithoutFees())
            ->get(route('fees.index'))
            ->assertRedirect(route('service-types.index'));
    }

    public function test_admin_is_redirected_to_service_settings(): void
    {
        $this->actingAs($this->admin())
            ->get(route('fees.index'))
            ->assertRedirect(route('service-types.index'));
    }
```

- [ ] **Step 2: Run only ServiceFeeTest to confirm**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=ServiceFeeTest 2>&1
```
Expected: all tests PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/ServiceFeeTest.php
git commit -m "test: CHG-004 update ServiceFeeTest for fees.index redirect"
```

---

### Task 7: Delete old Fees page files

**Files:**
- Delete: `resources/js/Pages/Fees/Index.vue`
- Delete: `resources/js/Pages/Fees/Partials/FeeModal.vue`

- [ ] **Step 1: Remove files via git**

```bash
git rm resources/js/Pages/Fees/Index.vue resources/js/Pages/Fees/Partials/FeeModal.vue
```

- [ ] **Step 2: Remove empty directories left by git rm**

```bash
rm -rf resources/js/Pages/Fees
```

- [ ] **Step 3: Verify directory is gone**

```bash
ls resources/js/Pages/Fees 2>&1
```
Expected: `ls: cannot access ... No such file or directory`

- [ ] **Step 4: Commit**

```bash
git commit -m "refactor: CHG-004 delete Fees/Index.vue and Fees/Partials/FeeModal.vue"
```

---

### Task 8: Run full test suite + build

**Files:** none

- [ ] **Step 1: Run full test suite**

```bash
docker exec saifzz-aircond-laravel.test-1 php artisan test 2>&1 | tail -20
```
Expected: all tests PASS, suite count unchanged (269/269 or higher).

- [ ] **Step 2: Build frontend**

```bash
docker compose exec -T laravel.test npm run build 2>&1 | tail -10
```
Expected: `✓ built in` with no errors.

- [ ] **Step 3: Mark CHG-004 TESTING in feedback doc**

In `docs/FEEDBACK-13062026.md`, change the CHG-004 row status from `OPEN` to `TESTING`.

- [ ] **Step 4: Commit**

```bash
git add docs/FEEDBACK-13062026.md
git commit -m "docs: mark CHG-004 TESTING"
```
