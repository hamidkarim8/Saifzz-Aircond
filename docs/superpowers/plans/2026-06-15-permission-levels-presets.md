# Permission Levels + Presets Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add admin-configurable, per-tenant L1/L2/L3 permission presets that fill the technician permission checkboxes, and remove Reminders from the technician sidebar.

**Architecture:** New `permission_presets` table holds one row per (tenant, level) with a JSON permission array. A `PermissionPreset` model exposes hardcoded `DEFAULTS` and a `forTenant()` resolver that returns DB rows or falls back to defaults (no seeding, no backfill). A single `update` controller endpoint (admin-gated, tenant-stamped) saves all three levels. The browser fills checkboxes from the resolved presets — `users.store`/`users.update` are unchanged (snapshot model). Frontend gets a separate `PresetsModal` on the Users page plus L1/L2/L3 quick-fill buttons in the user modal.

**Tech Stack:** Laravel 11 (PHP 8.5), Inertia + Vue 3, Tailwind, Pest/PHPUnit feature tests, Vite. Tests run via `docker exec saifzz-aircond-laravel.test-1 php artisan test`.

---

## File structure

- **Create** `database/migrations/2026_06_15_000002_create_permission_presets_table.php` — schema.
- **Create** `app/Models/PermissionPreset.php` — model, `DEFAULTS`, `forTenant()`.
- **Create** `app/Http/Controllers/PermissionPresetController.php` — `update` only.
- **Create** `app/Http/Requests/UpdatePermissionPresetRequest.php` — validation + authorize.
- **Create** `resources/js/permissionLabels.js` — shared permission→label map.
- **Create** `resources/js/Pages/Users/Partials/PresetsModal.vue` — preset editor.
- **Create** `tests/Feature/PermissionPresetTest.php` — feature tests.
- **Modify** `routes/web.php` — add `permission-presets.update` route inside the `can:manage_users` group.
- **Modify** `app/Http/Controllers/UserController.php:16-30` — add `presets` prop to `index`.
- **Modify** `resources/js/Pages/Users/Partials/UserModal.vue` — quick-fill buttons, shared labels, `presets` prop.
- **Modify** `resources/js/Pages/Users/Index.vue` — "Permission levels" button + `PresetsModal` + pass `presets`.
- **Modify** `resources/js/Layouts/AdminLayout.vue:32` — `adminOnly: true` on Reminders.

**Note on seeding:** presets are resolved lazily — `forTenant()` returns `DEFAULTS` when a tenant has no rows. No seeder change, no backfill migration. Rows are written only when an admin saves the Presets modal. This resolves the minor spec tension between "seed on boss create" and "lazy fallback" in favor of pure-lazy (simpler, zero data migration).

---

## Task 1: permission_presets migration

**Files:**
- Create: `database/migrations/2026_06_15_000002_create_permission_presets_table.php`
- Test: `tests/Feature/PermissionPresetTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PermissionPresetTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\PermissionPreset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PermissionPresetTest extends TestCase
{
    use RefreshDatabase;

    /** Create a boss admin that is its own tenant root. */
    private function boss(): User
    {
        $boss = User::factory()->admin()->create();
        $boss->update(['tenant_id' => $boss->id]);

        return $boss->fresh();
    }

    public function test_permission_presets_table_exists(): void
    {
        $this->assertTrue(Schema::hasColumn('permission_presets', 'tenant_id'));
        $this->assertTrue(Schema::hasColumn('permission_presets', 'level'));
        $this->assertTrue(Schema::hasColumn('permission_presets', 'permissions'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=test_permission_presets_table_exists`
Expected: FAIL — table/column does not exist.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_06_15_000002_create_permission_presets_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_presets', function (Blueprint $table) {
            $table->id();
            // tenant root = a boss (admin) user; preset is meaningless without it.
            $table->foreignId('tenant_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('level'); // 1, 2, 3
            $table->json('permissions');
            $table->timestamps();

            $table->unique(['tenant_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_presets');
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=test_permission_presets_table_exists`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_06_15_000002_create_permission_presets_table.php tests/Feature/PermissionPresetTest.php
git commit -m "feat: CHG-011 permission_presets table"
```

---

## Task 2: PermissionPreset model + forTenant resolver

**Files:**
- Create: `app/Models/PermissionPreset.php`
- Test: `tests/Feature/PermissionPresetTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/PermissionPresetTest.php`:

```php
    public function test_for_tenant_returns_defaults_when_no_rows(): void
    {
        $boss = $this->boss();

        $presets = PermissionPreset::forTenant($boss->id);

        $this->assertSame(PermissionPreset::DEFAULTS[1], $presets[1]);
        $this->assertSame(PermissionPreset::DEFAULTS[2], $presets[2]);
        $this->assertSame(PermissionPreset::DEFAULTS[3], $presets[3]);
    }

    public function test_for_tenant_returns_saved_rows_over_defaults(): void
    {
        $boss = $this->boss();
        PermissionPreset::create([
            'tenant_id' => $boss->id,
            'level' => 1,
            'permissions' => ['record_service'],
        ]);

        $presets = PermissionPreset::forTenant($boss->id);

        $this->assertSame(['record_service'], $presets[1]);
        // levels without a row still fall back to defaults
        $this->assertSame(PermissionPreset::DEFAULTS[2], $presets[2]);
    }

    public function test_for_tenant_strips_manage_users(): void
    {
        $boss = $this->boss();
        PermissionPreset::create([
            'tenant_id' => $boss->id,
            'level' => 3,
            'permissions' => ['view_reports', 'manage_users'],
        ]);

        $this->assertSame(['view_reports'], PermissionPreset::forTenant($boss->id)[3]);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=PermissionPresetTest`
Expected: FAIL — `Class "App\Models\PermissionPreset" not found`.

- [ ] **Step 3: Write the model**

Create `app/Models/PermissionPreset.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PermissionPreset extends Model
{
    protected $fillable = ['tenant_id', 'level', 'permissions'];

    protected function casts(): array
    {
        return ['permissions' => 'array'];
    }

    /**
     * Hardcoded baseline used when a tenant has not customised a level.
     * Admin-editable defaults (CHG-011). manage_users is never included.
     */
    public const DEFAULTS = [
        1 => ['view_clients', 'record_service', 'set_appointment', 'manage_service_types', 'manage_units'],
        2 => ['view_clients', 'record_service', 'set_appointment', 'manage_service_types', 'manage_units', 'collect_payment', 'edit_client'],
        3 => ['view_clients', 'record_service', 'set_appointment', 'manage_service_types', 'manage_units', 'collect_payment', 'edit_client', 'view_all_data', 'view_reports', 'export_data'],
    ];

    /**
     * Resolve the three level baselines for a tenant: saved rows when present,
     * otherwise DEFAULTS. manage_users is defensively stripped from every level.
     *
     * @return array<int, array<int, string>> keyed 1,2,3
     */
    public static function forTenant(?int $tenantId): array
    {
        $saved = $tenantId === null
            ? collect()
            : static::where('tenant_id', $tenantId)->get()->keyBy('level');

        $out = [];
        foreach ([1, 2, 3] as $level) {
            $perms = $saved->has($level)
                ? $saved->get($level)->permissions
                : self::DEFAULTS[$level];

            $out[$level] = array_values(array_filter(
                $perms,
                fn ($p) => ! in_array($p, User::ADMIN_ONLY_PERMISSIONS, true),
            ));
        }

        return $out;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=PermissionPresetTest`
Expected: PASS (all 4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Models/PermissionPreset.php tests/Feature/PermissionPresetTest.php
git commit -m "feat: CHG-011 PermissionPreset model + forTenant resolver"
```

---

## Task 3: Update endpoint (request + controller + route)

**Files:**
- Create: `app/Http/Requests/UpdatePermissionPresetRequest.php`
- Create: `app/Http/Controllers/PermissionPresetController.php`
- Modify: `routes/web.php` (inside the `can:manage_users` group, after the users routes)
- Test: `tests/Feature/PermissionPresetTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/PermissionPresetTest.php`:

```php
    public function test_admin_saves_presets_for_own_tenant(): void
    {
        $boss = $this->boss();

        $this->actingAs($boss)->put(route('permission-presets.update'), [
            'presets' => [
                1 => ['record_service'],
                2 => ['record_service', 'collect_payment'],
                3 => ['record_service', 'collect_payment', 'view_reports'],
            ],
        ])->assertRedirect();

        $this->assertSame(['record_service'], PermissionPreset::forTenant($boss->id)[1]);
        $this->assertSame(3, PermissionPreset::where('tenant_id', $boss->id)->count());
    }

    public function test_save_is_idempotent_upsert(): void
    {
        $boss = $this->boss();
        $payload = ['presets' => [1 => ['record_service'], 2 => [], 3 => []]];

        $this->actingAs($boss)->put(route('permission-presets.update'), $payload)->assertRedirect();
        $this->actingAs($boss)->put(route('permission-presets.update'), $payload)->assertRedirect();

        $this->assertSame(3, PermissionPreset::where('tenant_id', $boss->id)->count());
    }

    public function test_save_rejects_manage_users_and_unknown_keys(): void
    {
        $boss = $this->boss();

        $this->actingAs($boss)->put(route('permission-presets.update'), [
            'presets' => [1 => ['manage_users'], 2 => [], 3 => []],
        ])->assertSessionHasErrors('presets.1.0');

        $this->actingAs($boss)->put(route('permission-presets.update'), [
            'presets' => [1 => ['bogus_perm'], 2 => [], 3 => []],
        ])->assertSessionHasErrors('presets.1.0');
    }

    public function test_presets_are_tenant_isolated(): void
    {
        $khalid = $this->boss();
        $saifzz = $this->boss();

        $this->actingAs($khalid)->put(route('permission-presets.update'), [
            'presets' => [1 => ['record_service'], 2 => [], 3 => []],
        ])->assertRedirect();

        // Saifzz sees his own defaults, not Khalid's saved L1.
        $this->assertSame(PermissionPreset::DEFAULTS[1], PermissionPreset::forTenant($saifzz->id)[1]);
        $this->assertSame(0, PermissionPreset::where('tenant_id', $saifzz->id)->count());
    }

    public function test_technician_cannot_save_presets(): void
    {
        $boss = $this->boss();
        $tech = User::factory()->create(['tenant_id' => $boss->id, 'role' => User::ROLE_TECHNICIAN]);

        $this->actingAs($tech)->put(route('permission-presets.update'), [
            'presets' => [1 => [], 2 => [], 3 => []],
        ])->assertForbidden();
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=PermissionPresetTest`
Expected: FAIL — route `permission-presets.update` not defined.

- [ ] **Step 3: Write the form request**

Create `app/Http/Requests/UpdatePermissionPresetRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePermissionPresetRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is already behind can:manage_users; double-guard here.
        return $this->user()?->hasPermission('manage_users') ?? false;
    }

    public function rules(): array
    {
        $grantable = array_values(array_diff(User::PERMISSIONS, User::ADMIN_ONLY_PERMISSIONS));

        return [
            'presets' => ['required', 'array'],
            'presets.1' => ['present', 'array'],
            'presets.2' => ['present', 'array'],
            'presets.3' => ['present', 'array'],
            'presets.*.*' => ['string', Rule::in($grantable)],
        ];
    }
}
```

- [ ] **Step 4: Write the controller**

Create `app/Http/Controllers/PermissionPresetController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePermissionPresetRequest;
use App\Models\PermissionPreset;
use Illuminate\Http\RedirectResponse;

class PermissionPresetController extends Controller
{
    public function update(UpdatePermissionPresetRequest $request): RedirectResponse
    {
        $tenantId = $request->user()->tenantId();

        foreach ($request->validated()['presets'] as $level => $permissions) {
            PermissionPreset::updateOrCreate(
                ['tenant_id' => $tenantId, 'level' => (int) $level],
                ['permissions' => array_values($permissions)],
            );
        }

        return back()->with('success', 'Permission levels updated.');
    }
}
```

- [ ] **Step 5: Add the route**

In `routes/web.php`, inside the `Route::middleware('can:manage_users')->group(...)` block (around line 92-96, after the users routes), add:

```php
        Route::put('permission-presets', [PermissionPresetController::class, 'update'])->name('permission-presets.update');
```

Add the import at the top of `routes/web.php` near the other controller imports:

```php
use App\Http\Controllers\PermissionPresetController;
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=PermissionPresetTest`
Expected: PASS (all tests).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/UpdatePermissionPresetRequest.php app/Http/Controllers/PermissionPresetController.php routes/web.php tests/Feature/PermissionPresetTest.php
git commit -m "feat: CHG-011 tenant-scoped permission preset update endpoint"
```

---

## Task 4: UserController passes presets prop

**Files:**
- Modify: `app/Http/Controllers/UserController.php:16-30`
- Test: `tests/Feature/PermissionPresetTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/PermissionPresetTest.php`:

```php
    public function test_users_index_carries_tenant_presets(): void
    {
        $boss = $this->boss();
        PermissionPreset::create(['tenant_id' => $boss->id, 'level' => 1, 'permissions' => ['record_service']]);

        $this->actingAs($boss)->get(route('users.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('presets.1', ['record_service']));
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=test_users_index_carries_tenant_presets`
Expected: FAIL — `presets` prop missing.

- [ ] **Step 3: Add the prop**

In `app/Http/Controllers/UserController.php`, add the import near the top:

```php
use App\Models\PermissionPreset;
```

In `index()`, add a `presets` key to the `Inertia::render` array (after `grantablePermissions`):

```php
            'grantablePermissions' => array_values(array_diff(User::PERMISSIONS, User::ADMIN_ONLY_PERMISSIONS)),
            'presets' => PermissionPreset::forTenant($tenantId),
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test --filter=test_users_index_carries_tenant_presets`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/UserController.php tests/Feature/PermissionPresetTest.php
git commit -m "feat: CHG-011 expose tenant presets on users index"
```

---

## Task 5: Shared permission labels + UserModal quick-fill

**Files:**
- Create: `resources/js/permissionLabels.js`
- Modify: `resources/js/Pages/Users/Partials/UserModal.vue`

- [ ] **Step 1: Create the shared label module**

Create `resources/js/permissionLabels.js` (covers all 11 grantable permissions — fixes the two missing labels `manage_service_types` + `manage_units`):

```js
// Single source of truth for permission display labels (UserModal + PresetsModal).
export const permLabels = {
    view_clients: 'View clients & history',
    record_service: 'Record service visits',
    set_appointment: 'Schedule appointments',
    collect_payment: 'Collect payments',
    edit_client: 'Create & edit clients',
    view_reports: 'View reports dashboard',
    edit_fees: 'Manage price book',
    export_data: 'Export data to CSV',
    view_all_data: 'See all data (not just own jobs)',
    manage_service_types: 'Manage service types',
    manage_units: 'Manage client units',
};
```

- [ ] **Step 2: Wire UserModal to use it + add quick-fill buttons**

In `resources/js/Pages/Users/Partials/UserModal.vue`:

Replace the inline `permLabels` object (lines 16-26) and add the import. Change the top of the `<script setup>`:

```js
import { watch, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FormErrorSummary from '@/Components/FormErrorSummary.vue';
import InputError from '@/Components/InputError.vue';
import { permLabels } from '@/permissionLabels';

const props = defineProps({
    open: Boolean,
    user: { type: Object, default: null }, // null = create
    grantablePermissions: Array,
    presets: { type: Object, default: () => ({}) }, // { 1: [...], 2: [...], 3: [...] }
});
const emit = defineEmits(['close']);

const isEdit = computed(() => !!props.user);

const applyLevel = (level) => {
    form.permissions = [...(props.presets[level] ?? [])];
};
```

(Delete the old `const permLabels = { ... };` block entirely — it now comes from the import.)

In the template, add the quick-fill buttons immediately after the `<p class="mb-2 text-sm font-semibold text-ink">Permissions</p>` line and before the checkbox `<div class="grid ...">`:

```html
                        <div class="mb-2 flex flex-wrap gap-2">
                            <button
                                v-for="lvl in [1, 2, 3]"
                                :key="lvl"
                                type="button"
                                class="rounded-ra border border-line px-3 py-1.5 text-xs font-semibold text-ink-soft transition hover:border-primary hover:bg-primary-50 hover:text-primary"
                                @click="applyLevel(lvl)"
                            >
                                Level {{ lvl }}
                            </button>
                        </div>
```

- [ ] **Step 3: Build the frontend**

Run: `docker compose exec -T laravel.test npm run build`
Expected: build succeeds, no Vite errors.

- [ ] **Step 4: Commit**

```bash
git add resources/js/permissionLabels.js resources/js/Pages/Users/Partials/UserModal.vue
git commit -m "feat: CHG-011 level quick-fill buttons + shared perm labels in UserModal"
```

---

## Task 6: PresetsModal + Users/Index button

**Files:**
- Create: `resources/js/Pages/Users/Partials/PresetsModal.vue`
- Modify: `resources/js/Pages/Users/Index.vue`

- [ ] **Step 1: Read the current Users/Index.vue**

Read `resources/js/Pages/Users/Index.vue` to find the existing Add-user button, the `<UserModal>` usage, the page props (`grantablePermissions`, `presets`), and the modal open/close state. Match its style for the new button and modal wiring.

- [ ] **Step 2: Create PresetsModal**

Create `resources/js/Pages/Users/Partials/PresetsModal.vue`:

```vue
<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { permLabels } from '@/permissionLabels';

const props = defineProps({
    open: Boolean,
    grantablePermissions: Array,
    presets: { type: Object, default: () => ({}) }, // { 1: [...], 2: [...], 3: [...] }
});
const emit = defineEmits(['close']);

const form = useForm({
    presets: { 1: [], 2: [], 3: [] },
});

watch(() => props.open, (open) => {
    if (!open) return;
    form.clearErrors();
    form.presets = {
        1: [...(props.presets[1] ?? [])],
        2: [...(props.presets[2] ?? [])],
        3: [...(props.presets[3] ?? [])],
    };
});

const submit = () => {
    form.put(route('permission-presets.update'), {
        onSuccess: () => emit('close'),
        preserveScroll: true,
    });
};
</script>

<template>
    <Transition
        enter-active-class="transition duration-200" enter-from-class="opacity-0"
        leave-active-class="transition duration-150" leave-to-class="opacity-0"
    >
        <div
            v-if="open"
            class="fixed inset-0 z-50 flex items-end justify-center bg-navy-900/60 p-0 backdrop-blur-sm sm:items-center sm:p-4"
            @click.self="emit('close')"
        >
            <div class="w-full max-w-2xl rounded-t-rax bg-surface p-6 shadow-lift sm:rounded-rax max-h-[90vh] overflow-y-auto">
                <div class="mb-5 flex items-center justify-between">
                    <h3 class="text-lg font-bold text-navy-800">Permission levels</h3>
                    <button
                        type="button"
                        class="rounded-ra p-1 text-ink-muted transition hover:bg-surface-muted hover:text-ink"
                        @click="emit('close')"
                        aria-label="Close"
                    >
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M18 6 6 18M6 6l12 12" stroke-linecap="round" />
                        </svg>
                    </button>
                </div>

                <p class="mb-4 text-sm text-ink-soft">
                    These baselines auto-fill the permission checkboxes when you pick a level
                    while creating a technician. They do not change existing technicians.
                </p>

                <form class="space-y-6" @submit.prevent="submit">
                    <div v-for="lvl in [1, 2, 3]" :key="lvl">
                        <p class="mb-2 text-sm font-semibold text-ink">Level {{ lvl }}</p>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <label
                                v-for="perm in grantablePermissions"
                                :key="perm"
                                class="flex cursor-pointer items-start gap-3 rounded-ra border p-3 transition hover:bg-surface-muted"
                                :class="form.presets[lvl].includes(perm) ? 'border-primary bg-primary-50' : 'border-line'"
                            >
                                <input
                                    type="checkbox"
                                    :value="perm"
                                    v-model="form.presets[lvl]"
                                    class="mt-0.5 shrink-0 rounded border-line text-primary focus:ring-primary"
                                />
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-ink leading-snug">{{ permLabels[perm] ?? perm }}</p>
                                    <p class="text-xs text-ink-soft font-mono mt-0.5">{{ perm }}</p>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-2">
                        <button type="button" class="text-sm font-medium text-ink-soft hover:text-ink" @click="emit('close')">
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="rounded-ra bg-primary px-5 py-2.5 text-sm font-semibold text-white shadow-card transition hover:bg-primary-hover disabled:opacity-60"
                        >
                            Save levels
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </Transition>
</template>
```

- [ ] **Step 3: Wire PresetsModal into Users/Index.vue**

In `resources/js/Pages/Users/Index.vue`:

1. Add `presets` to the `defineProps` (alongside `users` and `grantablePermissions`):

```js
    presets: { type: Object, default: () => ({}) },
```

2. Import the modal and add open-state in `<script setup>`:

```js
import PresetsModal from './Partials/PresetsModal.vue';
import { ref } from 'vue'; // if ref not already imported

const presetsOpen = ref(false);
```

3. Add a **"Permission levels"** button next to the existing Add-user button in the header (match the existing button's container/classes; use a secondary style):

```html
                <button
                    type="button"
                    class="rounded-ra border border-line px-4 py-2 text-sm font-semibold text-ink-soft transition hover:border-primary hover:text-primary"
                    @click="presetsOpen = true"
                >
                    Permission levels
                </button>
```

4. Pass `presets` to the existing `<UserModal>` (add the prop):

```html
                :presets="presets"
```

5. Render `<PresetsModal>` near the existing `<UserModal>`:

```html
        <PresetsModal
            :open="presetsOpen"
            :grantable-permissions="grantablePermissions"
            :presets="presets"
            @close="presetsOpen = false"
        />
```

- [ ] **Step 4: Build the frontend**

Run: `docker compose exec -T laravel.test npm run build`
Expected: build succeeds, no Vite errors.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Users/Partials/PresetsModal.vue resources/js/Pages/Users/Index.vue
git commit -m "feat: CHG-011 PresetsModal + Permission levels button on Users page"
```

---

## Task 7: Remove Reminders from technician sidebar

**Files:**
- Modify: `resources/js/Layouts/AdminLayout.vue:32`

- [ ] **Step 1: Add the adminOnly flag**

In `resources/js/Layouts/AdminLayout.vue` line 32, change the Reminders nav item:

```js
            { label: 'Reminders', route: 'reminders.index', match: 'reminders', icon: IconBell, permission: 'view_clients', adminOnly: true, badge: reminderCount.value },
```

(Add `adminOnly: true`. The existing filter at line 45 — `(!i.adminOnly || isAdmin.value)` — then hides it from technicians. Routes are unchanged; admins still reach Reminders.)

- [ ] **Step 2: Build the frontend**

Run: `docker compose exec -T laravel.test npm run build`
Expected: build succeeds.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Layouts/AdminLayout.vue
git commit -m "feat: CHG-011 hide Reminders from technician sidebar"
```

---

## Task 8: Full verification

- [ ] **Step 1: Run the full test suite**

Run: `docker exec saifzz-aircond-laravel.test-1 php artisan test`
Expected: PASS — previous 258 tests + new PermissionPresetTest cases all green.

- [ ] **Step 2: Final production build**

Run: `docker compose exec -T laravel.test npm run build`
Expected: clean build, Vite manifest written.

- [ ] **Step 3: Mark CHG-011 TESTING in feedback doc**

In `docs/FEEDBACK-13062026.md`, change the CHG-011 row status from `OPEN` to `TESTING`.

- [ ] **Step 4: Commit**

```bash
git add docs/FEEDBACK-13062026.md
git commit -m "docs: mark CHG-011 TESTING (permission levels + presets shipped)"
```

---

## Deployment note

`permission_presets` is a new table — run `php artisan migrate` on prod after merge. No data backfill needed (lazy defaults). No reseed required.
