# Module 1 — Users Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build admin-only screen to create staff accounts, toggle granular permissions, and enable/disable accounts.

**Architecture:** Single Inertia page with modal CRUD following the Service Fees pattern. New `UserController` with `index/store/update/toggleActive` actions, all gated `can:manage_users`. `grantPermission()` on the User model silently drops admin-only permissions at the model layer (P1). Frontend uses `useForm` + a modal partial, matching `Fees/Partials/FeeModal.vue`.

**Tech Stack:** Laravel 11, Inertia.js v2, Vue 3 Composition API, Tailwind CSS design tokens

---

## File Map

**Create:**
- `app/Http/Controllers/UserController.php` — index/store/update/toggleActive
- `app/Http/Requests/StoreUserRequest.php` — create validation
- `app/Http/Requests/UpdateUserRequest.php` — update validation
- `resources/js/Pages/Users/Index.vue` — staff list + add button
- `resources/js/Pages/Users/Partials/UserModal.vue` — create/edit modal with permission checkboxes
- `tests/Feature/UserManagementTest.php` — feature tests

**Modify:**
- `database/factories/UserFactory.php` — add admin() and technician() states
- `routes/web.php` — add users route group inside auth middleware
- `resources/js/Layouts/AdminLayout.vue` — add Users nav item

---

## Task 1: UserFactory States

**Files:**
- Modify: `database/factories/UserFactory.php`

- [ ] **Step 1: Add admin() and technician() factory states**

Add `use App\Models\User;` to the imports at the top of the file (after the existing `use` statements).

After the existing `unverified()` method, append:

```php
public function admin(): static
{
    return $this->state(fn () => [
        'role' => User::ROLE_ADMIN,
        'active' => true,
    ]);
}

public function technician(): static
{
    return $this->state(fn () => [
        'role' => User::ROLE_TECHNICIAN,
        'active' => true,
    ]);
}
```

- [ ] **Step 2: Verify existing tests still pass**

```bash
cd /home/hamid/Saifzz-Aircond && php artisan test --filter ServiceFeeTest
```

Expected: All green (no regressions from factory change).

---

## Task 2: Write Failing Tests

**Files:**
- Create: `tests/Feature/UserManagementTest.php`

- [ ] **Step 1: Create the test file**

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function technician(array $overrides = []): User
    {
        return User::factory()->technician()->create($overrides);
    }

    // --- Authorization ---

    public function test_guest_is_redirected_from_users_index(): void
    {
        $this->get(route('users.index'))->assertRedirect(route('login'));
    }

    public function test_technician_with_all_grantable_permissions_cannot_access_any_route(): void
    {
        $grantable = array_values(array_diff(User::PERMISSIONS, User::ADMIN_ONLY_PERMISSIONS));
        $tech = $this->technician(['permissions' => $grantable]);

        $this->actingAs($tech)->get(route('users.index'))->assertForbidden();
        $this->actingAs($tech)->post(route('users.store'), [])->assertForbidden();
        $this->actingAs($tech)->put(route('users.update', $tech), [])->assertForbidden();
        $this->actingAs($tech)->patch(route('users.active', $tech))->assertForbidden();
    }

    // --- Index ---

    public function test_admin_can_view_users_index(): void
    {
        $this->actingAs($this->admin())->get(route('users.index'))->assertOk();
    }

    // --- Store ---

    public function test_admin_creates_technician_with_default_permissions(): void
    {
        $this->actingAs($this->admin())
            ->post(route('users.store'), [
                'name' => 'New Tech',
                'email' => 'tech@example.com',
                'password' => 'secret123',
            ])
            ->assertRedirect();

        $user = User::where('email', 'tech@example.com')->firstOrFail();
        $this->assertEquals(User::ROLE_TECHNICIAN, $user->role);
        $this->assertEqualsCanonicalizing(User::DEFAULT_TECHNICIAN_PERMISSIONS, $user->permissions);
        $this->assertTrue($user->active);
    }

    public function test_admin_creates_technician_with_custom_permissions(): void
    {
        $this->actingAs($this->admin())
            ->post(route('users.store'), [
                'name' => 'Fee Tech',
                'email' => 'feetech@example.com',
                'password' => 'secret123',
                'permissions' => ['view_clients', 'edit_fees'],
            ])
            ->assertRedirect();

        $user = User::where('email', 'feetech@example.com')->firstOrFail();
        $this->assertEqualsCanonicalizing(['view_clients', 'edit_fees'], $user->permissions);
    }

    public function test_admin_only_permission_in_store_payload_is_silently_dropped(): void
    {
        $this->actingAs($this->admin())
            ->post(route('users.store'), [
                'name' => 'Bad Actor',
                'email' => 'badactor@example.com',
                'password' => 'secret123',
                'permissions' => ['view_clients', 'manage_users'],
            ])
            ->assertSessionHasNoErrors();

        $user = User::where('email', 'badactor@example.com')->firstOrFail();
        $this->assertNotContains('manage_users', $user->permissions);
        $this->assertContains('view_clients', $user->permissions);
    }

    public function test_store_rejects_duplicate_email(): void
    {
        $this->technician(['email' => 'taken@example.com']);

        $this->actingAs($this->admin())
            ->post(route('users.store'), [
                'name' => 'Dupe',
                'email' => 'taken@example.com',
                'password' => 'secret123',
            ])
            ->assertSessionHasErrors('email');
    }

    // --- Update ---

    public function test_admin_updates_technician_name_and_permissions(): void
    {
        $tech = $this->technician(['name' => 'Old Name']);

        $this->actingAs($this->admin())
            ->put(route('users.update', $tech), [
                'name' => 'New Name',
                'permissions' => ['collect_payment', 'view_reports'],
            ])
            ->assertRedirect();

        $tech->refresh();
        $this->assertEquals('New Name', $tech->name);
        $this->assertEqualsCanonicalizing(['collect_payment', 'view_reports'], $tech->permissions);
    }

    public function test_cannot_update_another_admin(): void
    {
        $admin1 = $this->admin();
        $admin2 = $this->admin();

        $this->actingAs($admin1)
            ->put(route('users.update', $admin2), ['name' => 'Hacked', 'permissions' => []])
            ->assertForbidden();
    }

    // --- Toggle Active ---

    public function test_admin_can_toggle_technician_active_status(): void
    {
        $tech = $this->technician(['active' => true]);

        $this->actingAs($this->admin())
            ->patch(route('users.active', $tech))
            ->assertRedirect();
        $this->assertFalse($tech->fresh()->active);

        $this->actingAs($this->admin())
            ->patch(route('users.active', $tech))
            ->assertRedirect();
        $this->assertTrue($tech->fresh()->active);
    }

    public function test_admin_cannot_deactivate_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patch(route('users.active', $admin))
            ->assertStatus(422);
    }

    // --- P4 regression: deactivated user cannot log in ---

    public function test_deactivated_technician_cannot_log_in(): void
    {
        $this->technician([
            'email' => 'inactive@example.com',
            'password' => Hash::make('secret123'),
            'active' => false,
        ]);

        $this->post(route('login'), [
            'email' => 'inactive@example.com',
            'password' => 'secret123',
        ])->assertSessionHasErrors();

        $this->assertGuest();
    }
}
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
cd /home/hamid/Saifzz-Aircond && php artisan test --filter UserManagementTest
```

Expected: Tests fail with `Route [users.index] not defined` or similar — confirms tests are wired.

---

## Task 3: Form Requests

**Files:**
- Create: `app/Http/Requests/StoreUserRequest.php`
- Create: `app/Http/Requests/UpdateUserRequest.php`

- [ ] **Step 1: Create StoreUserRequest**

```php
<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage_users');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8'],
            'permissions' => ['nullable', 'array'],
            // Validates against ALL PERMISSIONS (including admin-only). Admin-only entries
            // pass validation but are silently dropped by grantPermission() — P1.
            'permissions.*' => ['string', Rule::in(User::PERMISSIONS)],
        ];
    }
}
```

- [ ] **Step 2: Create UpdateUserRequest**

```php
<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage_users');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(User::PERMISSIONS)],
        ];
    }
}
```

---

## Task 4: UserController

**Files:**
- Create: `app/Http/Controllers/UserController.php`

- [ ] **Step 1: Create the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Users/Index', [
            'users' => User::orderBy('name')->get(['id', 'name', 'email', 'role', 'active', 'permissions']),
            'grantablePermissions' => array_values(array_diff(User::PERMISSIONS, User::ADMIN_ONLY_PERMISSIONS)),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => User::ROLE_TECHNICIAN,
        ]);

        // booted() sets DEFAULT_TECHNICIAN_PERMISSIONS when permissions is null.
        // If caller sent explicit permissions, replace defaults and re-grant each through
        // grantPermission() so admin-only entries are silently dropped (P1).
        if ($request->has('permissions')) {
            $user->permissions = [];
            foreach ($request->permissions ?? [] as $p) {
                $user->grantPermission($p);
            }
            $user->save();
        }

        return back()->with('success', 'User created.');
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        if ($user->isAdmin()) {
            abort(403);
        }

        $user->name = $request->name;
        $user->permissions = [];
        foreach ($request->permissions ?? [] as $p) {
            $user->grantPermission($p);
        }
        $user->save();

        return back()->with('success', 'User updated.');
    }

    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            throw ValidationException::withMessages(['active' => 'Cannot deactivate your own account.']);
        }

        $user->update(['active' => ! $user->active]);

        return back()->with('success', $user->active ? 'Account activated.' : 'Account deactivated.');
    }
}
```

---

## Task 5: Routes

**Files:**
- Modify: `routes/web.php`

- [ ] **Step 1: Add UserController import**

At the top of `routes/web.php`, with the other `use` statements, add:

```php
use App\Http\Controllers\UserController;
```

- [ ] **Step 2: Add users route group**

Inside the `Route::middleware('auth')->group(function () {` block, after the fees group (after the closing `});` of `can:edit_fees`), add:

```php
// Users (module 1) — staff management; manage_users is admin-only (P1), so only admins reach these.
Route::middleware('can:manage_users')->group(function () {
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::patch('users/{user}/active', [UserController::class, 'toggleActive'])->name('users.active');
});
```

- [ ] **Step 3: Run UserManagement tests**

```bash
cd /home/hamid/Saifzz-Aircond && php artisan test --filter UserManagementTest
```

Expected: All 12 tests pass.

- [ ] **Step 4: Run full suite for regressions**

```bash
cd /home/hamid/Saifzz-Aircond && php artisan test
```

Expected: All tests pass (count up by 12 from baseline of 138).

- [ ] **Step 5: Commit backend**

```bash
git add app/Http/Controllers/UserController.php \
        app/Http/Requests/StoreUserRequest.php \
        app/Http/Requests/UpdateUserRequest.php \
        database/factories/UserFactory.php \
        routes/web.php \
        tests/Feature/UserManagementTest.php
git commit -m "feat(users): UserController, form requests, routes, tests (module 1 backend)"
```

---

## Task 6: Vue — UserModal.vue

**Files:**
- Create: `resources/js/Pages/Users/Partials/UserModal.vue`

- [ ] **Step 1: Create the modal partial**

```vue
<script setup>
import { watch, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    open: Boolean,
    user: { type: Object, default: null }, // null = create
    grantablePermissions: Array,
});
const emit = defineEmits(['close']);

const isEdit = computed(() => !!props.user);

const permLabels = {
    view_clients: 'View clients & history',
    record_service: 'Record service visits',
    set_appointment: 'Schedule appointments',
    collect_payment: 'Collect payments',
    edit_client: 'Create & edit clients',
    view_reports: 'View reports dashboard',
    edit_fees: 'Manage price book',
    export_data: 'Export data to CSV',
};

const form = useForm({
    name: '',
    email: '',
    password: '',
    permissions: [],
});

watch(() => props.open, (open) => {
    if (!open) return;
    form.clearErrors();
    if (props.user) {
        form.name = props.user.name;
        form.email = props.user.email;
        form.password = '';
        form.permissions = [...(props.user.permissions ?? [])];
    } else {
        form.reset();
    }
});

const submit = () => {
    if (isEdit.value) {
        form.put(route('users.update', props.user.id), {
            onSuccess: () => emit('close'),
            preserveScroll: true,
        });
    } else {
        form.post(route('users.store'), {
            onSuccess: () => emit('close'),
            preserveScroll: true,
        });
    }
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
            <div class="w-full max-w-lg rounded-t-rax bg-surface p-6 shadow-lift sm:rounded-rax">
                <h3 class="text-lg font-bold text-navy-800">{{ isEdit ? 'Edit user' : 'Add user' }}</h3>

                <form class="mt-5 space-y-4" @submit.prevent="submit">
                    <div>
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Name</label>
                        <input
                            v-model="form.name"
                            type="text"
                            class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary"
                            placeholder="Full name"
                        />
                        <p v-if="form.errors.name" class="mt-1 text-sm text-danger">{{ form.errors.name }}</p>
                    </div>

                    <div v-if="!isEdit">
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Email</label>
                        <input
                            v-model="form.email"
                            type="email"
                            class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary"
                            placeholder="staff@example.com"
                        />
                        <p v-if="form.errors.email" class="mt-1 text-sm text-danger">{{ form.errors.email }}</p>
                    </div>

                    <div v-if="!isEdit">
                        <label class="mb-1.5 block text-sm font-semibold text-ink">Password</label>
                        <input
                            v-model="form.password"
                            type="password"
                            class="w-full rounded-ra border-line bg-surface text-ink shadow-card focus:border-primary focus:ring-primary"
                            placeholder="Min. 8 characters"
                        />
                        <p v-if="form.errors.password" class="mt-1 text-sm text-danger">{{ form.errors.password }}</p>
                    </div>

                    <div>
                        <p class="mb-2 text-sm font-semibold text-ink">Permissions</p>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <label
                                v-for="perm in grantablePermissions"
                                :key="perm"
                                class="flex cursor-pointer items-start gap-2.5 rounded-ra border border-line p-3 hover:bg-surface-muted"
                                :class="{ 'border-primary bg-primary-50': form.permissions.includes(perm) }"
                            >
                                <input
                                    type="checkbox"
                                    :value="perm"
                                    v-model="form.permissions"
                                    class="mt-0.5 rounded border-line text-primary focus:ring-primary"
                                />
                                <span class="text-sm text-ink">{{ permLabels[perm] ?? perm }}</span>
                            </label>
                        </div>
                        <p v-if="form.errors.permissions" class="mt-1 text-sm text-danger">{{ form.errors.permissions }}</p>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-2">
                        <button type="button" class="text-sm font-medium text-ink-soft hover:text-ink" @click="emit('close')">Cancel</button>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="rounded-ra bg-primary px-5 py-2.5 text-sm font-semibold text-white shadow-card transition hover:bg-primary-hover disabled:opacity-60"
                        >
                            {{ isEdit ? 'Save changes' : 'Create user' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </Transition>
</template>
```

---

## Task 7: Vue — Users/Index.vue

**Files:**
- Create: `resources/js/Pages/Users/Index.vue`

- [ ] **Step 1: Create the page**

```vue
<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import UserModal from './Partials/UserModal.vue';

defineProps({
    users: Array,
    grantablePermissions: Array,
});

const modalOpen = ref(false);
const editing = ref(null);

const openAdd = () => { editing.value = null; modalOpen.value = true; };
const openEdit = (user) => { editing.value = user; modalOpen.value = true; };

const toggleActive = (user) => {
    router.patch(route('users.active', user.id), {}, { preserveScroll: true });
};

const roleBadge = {
    admin: 'bg-primary-50 text-primary',
    technician: 'bg-surface-muted text-ink-soft',
};
</script>

<template>
    <Head title="Users" />

    <AdminLayout>
        <template #header>
            <h1 class="text-lg font-bold tracking-tight text-navy-800">Users</h1>
        </template>

        <div class="mb-5 flex items-center justify-between">
            <p class="text-sm text-ink-soft">Staff accounts. Only admins can create or modify users.</p>
            <button
                class="inline-flex items-center gap-2 rounded-ra bg-primary px-4 py-2.5 text-sm font-semibold text-white shadow-card transition hover:bg-primary-hover"
                @click="openAdd"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path d="M12 5v14M5 12h14" stroke-linecap="round" />
                </svg>
                Add user
            </button>
        </div>

        <div class="overflow-hidden rounded-ral border border-line bg-surface shadow-card">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-line bg-surface-muted text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">
                        <th class="px-5 py-3">Name</th>
                        <th class="px-5 py-3">Email</th>
                        <th class="px-5 py-3">Role</th>
                        <th class="px-5 py-3">Permissions</th>
                        <th class="px-5 py-3">Active</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="user in users" :key="user.id" class="hover:bg-surface-muted/50">
                        <td class="px-5 py-3.5 font-medium text-ink">{{ user.name }}</td>
                        <td class="px-5 py-3.5 text-ink-soft">{{ user.email }}</td>
                        <td class="px-5 py-3.5">
                            <span
                                class="inline-block rounded-full px-2.5 py-0.5 text-[11px] font-semibold capitalize"
                                :class="roleBadge[user.role] ?? roleBadge.technician"
                            >
                                {{ user.role }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5 text-ink-soft">
                            <span v-if="user.role === 'admin'" class="text-xs font-semibold text-primary">All</span>
                            <span v-else class="tabular-nums">{{ user.permissions?.length ?? 0 }} / {{ grantablePermissions.length }}</span>
                        </td>
                        <td class="px-5 py-3.5">
                            <button
                                v-if="user.role !== 'admin'"
                                class="relative inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors"
                                :class="user.active ? 'bg-ok' : 'bg-line'"
                                :title="user.active ? 'Deactivate' : 'Activate'"
                                @click="toggleActive(user)"
                            >
                                <span
                                    class="pointer-events-none inline-block h-4 w-4 rounded-full bg-white shadow-card transition-transform"
                                    :class="user.active ? 'translate-x-4' : 'translate-x-0'"
                                />
                            </button>
                            <span v-else class="text-xs text-ink-muted">—</span>
                        </td>
                        <td class="px-5 py-3.5 text-right">
                            <button
                                v-if="user.role !== 'admin'"
                                class="text-sm font-medium text-primary hover:text-primary-hover"
                                @click="openEdit(user)"
                            >
                                Edit
                            </button>
                        </td>
                    </tr>
                    <tr v-if="!users.length">
                        <td colspan="6" class="px-5 py-8 text-center text-sm text-ink-soft">No users found.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <UserModal
            :open="modalOpen"
            :user="editing"
            :grantable-permissions="grantablePermissions"
            @close="modalOpen = false"
        />
    </AdminLayout>
</template>
```

---

## Task 8: AdminLayout Nav Item

**Files:**
- Modify: `resources/js/Layouts/AdminLayout.vue`

- [ ] **Step 1: Add Users nav item**

In `AdminLayout.vue`, find the `nav` computed array. Add the Users item after the Clients item:

```js
const nav = computed(() => [
    { label: 'Dashboard', route: 'dashboard', icon: 'grid', permission: null },
    { label: 'Clients', route: 'clients.index', match: 'clients', icon: 'users', permission: 'view_clients' },
    { label: 'Users', route: 'users.index', match: 'users', icon: 'users', permission: 'manage_users' },
    { label: 'Service Records', route: 'service-records.index', match: 'service-records', icon: 'clipboard', permission: 'record_service' },
    { label: 'Appointments', route: 'appointments.index', match: 'appointments', icon: 'calendar', permission: 'set_appointment' },
    { label: 'Reminders', route: 'reminders.index', match: 'reminders', icon: 'bell', permission: 'view_clients' },
    { label: 'Service Fees', route: 'fees.index', match: 'fees', icon: 'tag', permission: 'edit_fees' },
].filter((i) => i.permission === null || can.value[i.permission]));
```

`manage_users` is in `auth.can` (set to `true` for admins via `Gate::before`, `false` for technicians). This nav item appears for admins only. ✓

---

## Task 9: Build, Verify, Commit Frontend

- [ ] **Step 1: Build assets**

```bash
cd /home/hamid/Saifzz-Aircond && npm run build
```

Expected: No errors.

- [ ] **Step 2: Run full test suite**

```bash
cd /home/hamid/Saifzz-Aircond && php artisan test
```

Expected: All tests pass (150 tests / ~439 assertions).

- [ ] **Step 3: Eyeball in browser**

Start dev server: `npm run dev`

Log in as admin. Verify:
- "Users" appears in sidebar nav
- `/users` loads the staff table with name/email/role/permissions/active columns
- "Add user" opens modal: name + email + password fields + 8 permission checkboxes
- Creating a user with custom permissions saves and closes modal; table refreshes
- Edit button on technician row opens modal pre-filled (no email/password fields)
- Active toggle switch flips state and updates row immediately
- Log in as a technician: no "Users" nav item; direct GET `/users` returns 403

- [ ] **Step 4: Commit frontend**

```bash
git add resources/js/Pages/Users/ resources/js/Layouts/AdminLayout.vue
git commit -m "feat(users): Users/Index page, UserModal, nav item (module 1 frontend)"
```
